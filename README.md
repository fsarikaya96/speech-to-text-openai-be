# Laravel Speech To Text Analysis OpenAI

# Install

**1. Clone Project**

```
  $ https://github.com/fsarikaya96/speech-to-text-openai-be.git
```

**2. Run Project**

```
  $ cp .env.example .env
  $ composer install
  $ php artisan serve
```

# CURL

**Get Analysis**

```
curl -X POST 'http://127.0.0.1:8000/api/upload' \
  -H 'Accept: application/json' \
  -F "audioFile=@path/to/example.mp4" \
  -F "textFile=@path/to/test.txt"
```
