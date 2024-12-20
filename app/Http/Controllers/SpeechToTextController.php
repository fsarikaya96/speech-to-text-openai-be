<?php

namespace App\Http\Controllers;

use App\Http\Requests\SpeechToTextRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class SpeechToTextController extends Controller
{
    public function upload(Request $request): JsonResponse
    {
        try {
            if ($request->hasFile('audioFile') && $request->hasFile('textFile')) {
                $audioFile = $request->file('audioFile');
                $textFile = $request->file('textFile');

                // Aldığımız dosyaları storage/app/public altında saklıyoruz.
                $audioPath = $audioFile->store('audio_files', 'public');
                $textPath = $textFile->store('text_files', 'public');

                // Video ve Text dosyasını alırız.
                $audioPath = public_path('storage/' . $audioPath);
                $textPath = public_path('storage/' . $textPath);

                // Orjinal texti alıyoruz.
                $originalText = File::get($textPath);

                // Ses kaydını metine çevirir.
                $speechToTextResponse = $this->speechToText($audioPath);

                // Farklı dilde bir dosya yüklendiyse boş döner.
                if (empty($speechToTextResponse['transcript'])) {
                    return response()->json(['status' => 'failure', 'message' => "Yüklemiş olduğunuz ses dosyasını kontrol edin. Desteklenen dil; TR"]);
                }
                // Orjinal metin ile ses kaydında ki transcript'i analiz eder.
                $analysisFromOpenAI = $this->getErrorAnalysisFromOpenAI($originalText, $speechToTextResponse['transcript']);

                // Dosyaların var olup olmadığını kontrol et varsa sil.
                if (File::exists($audioPath) && (File::exists($textPath))) {
                    File::delete($audioPath);
                    File::delete($textPath);
                }

                $result = [
                    'speech_to_text' => $speechToTextResponse,
                    'analysis_from_openai' => $analysisFromOpenAI,
                ];

                return response()->json(['status' => 'success', 'message' => $result]);
            }
            return response()->json(['status' => 'failure', 'message' => 'Dosya eklenemedi.']);

        } catch (\Exception $exception) {
            return response()->json(['status' => 'failure', 'message' => $exception->getMessage()]);
        }
    }

    private function speechToText($audioPath): array
    {
        $rawAudio = file_get_contents($audioPath);

        $language = 'tr';
        $model = 'nova-2';

        $requestUrl = sprintf("https://api.deepgram.com/v1/listen?language=%s&model=%s", $language, $model);

        $client = new \GuzzleHttp\Client();

        $headers = [
            "Accept" => "application/json",
            "Content-Type" => "audio/wave",
            "Authorization" => "Token " . env("DEEPGRAM_API_KEY")
        ];

        $response = $client->request('POST', $requestUrl, [
            'body' => $rawAudio,
            'headers' => $headers,
            'verify' => false
        ]);

        // İstek başarısız ise return et
        if ($response->getStatusCode() != 200) {
            return [];
        }
        $speechToTextResponse = json_decode($response->getBody()->getContents(), true);

        // Ses kaydını metine çeviriyoruz.
        $transcript = $speechToTextResponse['results']['channels'][0]['alternatives'][0]['transcript'] ?? null;

        // Confidence değeri
        $confidence = $speechToTextResponse['results']['channels'][0]['alternatives'][0]['confidence'] ?? null;

        // Duration (ses dosyasının süresi)
        $duration = $speechToTextResponse['metadata']['duration'] ?? null;

        // Kelime düzeyindeki zaman damgaları
        $words = $speechToTextResponse['results']['channels'][0]['alternatives'][0]['words'] ?? null;

        // Okuma hızı
        $wordCount = count($words); // Kelime sayısını al
        $readingSpeed = ($wordCount / $duration) * 60; // Kelime/dakika

        // Ondalıklı biçimlendir Örn; 4.7999997 --> 4.80
        $readingSpeed = number_format($readingSpeed, 2);
        $duration = number_format($duration, 2);

        // confidence Örn; 0.99902344 --> %99.90
        $confidence = $confidence * 100;
        $confidence = number_format($confidence, 2);

        $wordsArray = array_map(function ($item) {
            $item['start'] = number_format($item['start'], 2);
            $item['end'] = number_format($item['end'], 2);

            $item['confidence'] = $item['confidence'] * 100;
            $item['confidence'] = number_format($item['confidence'], 2);

            return $item;
        }, $words);

        return [
            'transcript' => $transcript,
            'confidence' => $confidence,
            'duration' => $duration,
            'reading_speed' => $readingSpeed,
            'words' => $wordsArray,
        ];
    }

    private function getErrorAnalysisFromOpenAI(string $originalText, string $speechToText): array
    {
        $requestUrl = 'https://api.openai.com/v1/chat/completions';

        $client = new \GuzzleHttp\Client();

        $data = [
            "model" => "gpt-4o-mini",
            "messages" => [
                [
                    "role" => "system",
                    "content" => "Transkripte edilmiş metin içerisinde hataları analiz et ve şu şekilde detaylandır:

                                1. Hatalı kelimeleri {bu formatta} işaretle.
                                2. Cümlelerin sonunda neden hatalı olduğunu açıklamak için 'Açıklama:' ile başlayarak birer cümle yaz.
                                3. Hata türlerini ve sayılarını belirt:
                                   - addition_errors: Toplam ekleme hatası sayısı.
                                   - omission_errors: Toplam eksik hata sayısı.
                                   - reversal_errors: Toplam ters çevirme hata sayısı.
                                   - repetition_errors: Toplam tekrar hatası sayısı.
                                4. Dakika başına kelime (WPM) hızını belirt: 'reading_speed: x.xx'.

                                Örnek:
                                bir hafta önce {annesi ile} ablası çarşıya çıkmışlardı hakan evde yalnız kalmıştı derslerini bitirdikten sonra aklına {reçel} {reçel} yemek gelmişti.
                                Açıklama:
                                1. 'annesi ile' ifadesi bağlam hatası içeriyor.
                                2. 'reçel' kelimesi iki kere tekrar edilmiştir.
                                Hatalar:
                                addition_errors: 1
                                omission_errors: 0
                                reversal_errors: 0
                                repetition_errors: 0
                                Okuma Hızı:
                                reading_speed: 67.57
                                "

                ],
                [
                    "role" => "user",
                    "content" => sprintf("Doğru metin: %s Transkripte edilmiş metin: %s", $originalText, $speechToText)
                ]
            ]
        ];

        $headers = [
            'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
            'Content-Type' => 'application/json'
        ];

        $response = $client->request('POST', $requestUrl, [
            'json' => $data,
            'headers' => $headers,
            'verify' => false
        ]);

        // İstek başarısız ise return et
        if ($response->getStatusCode() != 200) {
            return [];
        }
        $analysisText = json_decode($response->getBody()->getContents(), true);

        $content = $analysisText['choices'][0]['message']['content'] ?? null;

        preg_match('/(.*?)Açıklama:/s', $content, $part1);
        preg_match('/Açıklama:\s*(.*?)Hatalar:/s', $content, $part2);
        preg_match('/Hatalar:\s*(.*?)Okuma Hızı:/s', $content, $part3);
        preg_match('/Okuma Hızı:\s*reading_speed:\s*(\d+(\.\d+)?)/', $content, $part4);

        $text = isset($part1[1]) ? trim($part1[1]) : '';
        $description = isset($part2[1]) ? trim($part2[1]) : '';
        $errors = isset($part3[1]) ? trim($part3[1]) : '';
        $readingSpeed = isset($part4[1]) ? floatval($part4[1]) : 0.0;

        preg_match_all('/([a-z_]+_errors): (\d+)/', $errors, $errorMatches);

        $errorsArray = [];
        for ($i = 0; $i < count($errorMatches[1]); $i++) {
            $errorsArray[$errorMatches[1][$i]] = (int)$errorMatches[2][$i];
        }

        return [
            'text' => $text,
            'description' => $description,
            'errors' => $errorsArray,
            'reading_speed' => $readingSpeed,
        ];
    }
}
