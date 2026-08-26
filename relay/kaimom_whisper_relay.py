#!/usr/bin/env python3
"""kaimomデモ用のwhisper中継。デモ(heteml)は共用サーバーでwhisper.cppを実行
できないため、音声をこの中継(自社サーバー)が受けて文字起こしする。
- POST /transcribe?name=rec.m4a  (Bearer必須・音声そのまま) -> {"job": id}
- GET  /status/<job>             -> {"status","text","segments","duration","error"}
- POST /summarize {"prompt"}     -> ローカルOllama(gemma4, think:false) -> {"text"}
標準ライブラリのみ。whisperは1本ずつ直列処理(GPU/CPU競合を避ける)。
systemd user unit: kaimom-whisper-relay.service (port 18344)
"""
import http.server
import json
import os
import queue
import subprocess
import tempfile
import threading
import time
import urllib.parse
import urllib.request
import uuid

PORT = int(os.environ.get("KAIMOM_RELAY_PORT", "18344"))
TOKEN = os.environ.get("KAIMOM_RELAY_TOKEN", "")
WHISPER_BIN = os.environ.get("KAIMOM_WHISPER_BIN", "/home/kojima/work/kaimom/vendor/whisper.cpp/build/bin/whisper-cli")
WHISPER_MODEL = os.environ.get("KAIMOM_WHISPER_MODEL", "/mnt/data/kaimom/models/ggml-large-v3-turbo.bin")
THREADS = int(os.environ.get("KAIMOM_WHISPER_THREADS", "12"))
FFMPEG = os.environ.get("KAIMOM_FFMPEG", "ffmpeg")
OLLAMA = os.environ.get("KAIMOM_OLLAMA_URL", "http://127.0.0.1:11434")
MODEL = os.environ.get("KAIMOM_LLM_MODEL", "gemma4:12b-it-qat")
RATE_PER_HOUR = int(os.environ.get("KAIMOM_RELAY_RATE", "12"))
MAX_BODY = int(os.environ.get("KAIMOM_RELAY_MAX_MB", "20")) * 1024 * 1024
MAX_AUDIO_SEC = int(os.environ.get("KAIMOM_RELAY_MAX_SEC", "900"))  # デモは15分まで

jobs = {}          # id -> dict(status,text,segments,duration,error,ts,path)
job_q = queue.Queue()
hits = {}          # ip -> [timestamps]
lock = threading.Lock()


def rate_ok(ip):
    now = time.time()
    with lock:
        lst = [t for t in hits.get(ip, []) if t > now - 3600]
        if len(lst) >= RATE_PER_HOUR:
            hits[ip] = lst
            return False
        lst.append(now)
        hits[ip] = lst
    return True


def cleanup_loop():
    while True:
        time.sleep(600)
        cutoff = time.time() - 3600
        with lock:
            for jid in list(jobs):
                if jobs[jid]["ts"] < cutoff:
                    p = jobs[jid].get("path")
                    if p and os.path.exists(p):
                        try:
                            os.unlink(p)
                        except OSError:
                            pass
                    del jobs[jid]


def transcribe(path):
    wav = path + ".16k.wav"
    subprocess.run([FFMPEG, "-y", "-loglevel", "error", "-i", path,
                    "-ar", "16000", "-ac", "1", "-t", str(MAX_AUDIO_SEC), "-f", "wav", wav],
                   check=True, timeout=300)
    of = path + ".whisper"
    subprocess.run([WHISPER_BIN, "-m", WHISPER_MODEL, "-l", "ja", "-t", str(THREADS),
                    "-oj", "-of", of, "--no-prints", wav],
                   check=True, timeout=3600,
                   stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    with open(of + ".json", encoding="utf-8") as f:
        d = json.load(f)
    os.unlink(wav)
    os.unlink(of + ".json")
    segs, texts, dur = [], [], 0.0
    for t in d.get("transcription", []):
        txt = (t.get("text") or "").strip()
        if not txt:
            continue
        fr = t.get("offsets", {}).get("from", 0) / 1000.0
        to = t.get("offsets", {}).get("to", 0) / 1000.0
        segs.append([round(fr, 2), round(to, 2), txt])
        texts.append(txt)
        dur = max(dur, to)
    return "\n".join(texts), segs, dur


def worker_loop():
    while True:
        jid = job_q.get()
        with lock:
            job = jobs.get(jid)
            if not job:
                continue
            job["status"] = "running"
        try:
            text, segs, dur = transcribe(job["path"])
            with lock:
                job.update(status="done", text=text, segments=segs, duration=dur)
        except Exception as e:  # noqa: BLE001 - ジョブ失敗として返す
            with lock:
                job.update(status="failed", error=str(e)[:300])
        finally:
            try:
                os.unlink(job["path"])
            except OSError:
                pass
            job["path"] = ""


def summarize(prompt):
    body = json.dumps({
        "model": MODEL,
        "messages": [{"role": "user", "content": prompt}],
        "stream": False, "format": "json", "think": False,
        "options": {"temperature": 0.2, "num_predict": 1600},
    }).encode()
    req = urllib.request.Request(OLLAMA.rstrip("/") + "/api/chat", data=body,
                                 headers={"Content-Type": "application/json"})
    with urllib.request.urlopen(req, timeout=280) as r:
        d = json.load(r)
    return d.get("message", {}).get("content", "")


class H(http.server.BaseHTTPRequestHandler):
    def _json(self, code, obj):
        body = json.dumps(obj, ensure_ascii=False).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def _auth(self):
        if not TOKEN:
            self._json(500, {"error": "token not configured"})
            return False
        if self.headers.get("Authorization", "") != "Bearer " + TOKEN:
            self._json(401, {"error": "unauthorized"})
            return False
        return True

    def do_GET(self):
        if not self._auth():
            return
        if self.path.startswith("/status/"):
            jid = urllib.parse.unquote(self.path[len("/status/"):])
            with lock:
                job = jobs.get(jid)
                if not job:
                    return self._json(404, {"error": "no such job"})
                out = {k: job.get(k, "") for k in ("status", "text", "segments", "duration", "error")}
            return self._json(200, out)
        self._json(404, {"error": "not found"})

    def do_POST(self):
        if not self._auth():
            return
        ip = self.client_address[0]
        length = int(self.headers.get("Content-Length", "0"))
        if length <= 0 or length > MAX_BODY:
            return self._json(413, {"error": "body too large"})
        parsed = urllib.parse.urlparse(self.path)
        if parsed.path == "/transcribe":
            if not rate_ok(ip):
                return self._json(429, {"error": "rate limited"})
            name = urllib.parse.parse_qs(parsed.query).get("name", ["rec.wav"])[0]
            ext = os.path.splitext(name)[1].lower().lstrip(".")
            if ext not in ("wav", "mp3", "m4a", "mp4", "aac", "flac", "ogg", "oga", "webm", "wma", "amr", "3gp"):
                return self._json(400, {"error": "unsupported format"})
            data = self.rfile.read(length)
            fd, path = tempfile.mkstemp(suffix="." + ext, prefix="kaimom_")
            with os.fdopen(fd, "wb") as f:
                f.write(data)
            jid = uuid.uuid4().hex
            with lock:
                jobs[jid] = {"status": "queued", "text": "", "segments": [],
                             "duration": 0, "error": "", "ts": time.time(), "path": path}
            job_q.put(jid)
            return self._json(200, {"job": jid})
        if parsed.path == "/summarize":
            if not rate_ok(ip):
                return self._json(429, {"error": "rate limited"})
            try:
                d = json.loads(self.rfile.read(length))
                text = summarize(str(d.get("prompt", ""))[:40000])
                return self._json(200, {"text": text})
            except Exception as e:  # noqa: BLE001
                return self._json(502, {"error": str(e)[:200]})
        self._json(404, {"error": "not found"})

    def log_message(self, fmt, *args):
        print("[kaimom-relay]", self.client_address[0], fmt % args, flush=True)


def main():
    threading.Thread(target=worker_loop, daemon=True).start()
    threading.Thread(target=cleanup_loop, daemon=True).start()
    srv = http.server.ThreadingHTTPServer(("0.0.0.0", PORT), H)
    print(f"[kaimom-relay] listening :{PORT} model={os.path.basename(WHISPER_MODEL)}", flush=True)
    srv.serve_forever()


if __name__ == "__main__":
    main()
