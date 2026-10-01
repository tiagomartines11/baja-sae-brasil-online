FROM python:3.12-slim

# Piper: neural text-to-speech that runs locally on CPU. Used by baja-app to
# generate timer announcements (juiz/timer_tts.php); nothing leaves the stack.
# Pinned: the HTTP API (POST /synthesize) has moved between releases.
RUN pip install --no-cache-dir "piper-tts[http]==1.8.0"

# The voice is baked into the image so the service works with no internet.
# Other pt_BR voices: https://huggingface.co/rhasspy/piper-voices
ARG TTS_VOICE=pt_BR-faber-medium
ENV TTS_VOICE=${TTS_VOICE}
RUN python -m piper.download_voices --data-dir /voices ${TTS_VOICE}

EXPOSE 5000

CMD ["sh", "-c", "exec python -m piper.http_server -m ${TTS_VOICE} --data-dir /voices --host 0.0.0.0 --port 5000"]
