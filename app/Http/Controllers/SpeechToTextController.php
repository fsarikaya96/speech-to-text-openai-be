<?php

namespace App\Http\Controllers;

use App\Http\Requests\SpeechToTextRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class SpeechToTextController extends Controller
{
    public function upload(SpeechToTextRequest $request): JsonResponse
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
            "model" => "gpt-4-turbo",
            "temperature" => 0,
            "messages" => [
                [
                    "role" => "system",
                    "content" => "Transkripte edilmiş metni doğru metinle karşılaştırarak hataları analiz et ve aşağıdaki formatta çıktı üret:

                    1. Aşağıdaki maddelere göre hatalı kelimeleri {} formatında işaretle:
                        - Hatalı kelimeler {} formatında işaretlenmelidir ve açıklamalar yapılmalıdır.
                        - Özel isimler, özel karakterler dışında kalan kelimeler hatalı kabul edilmemelidir ve herhangi bir kategori ile bağlanmamalıdır.

                    2. Aşağıdaki maddeye göre kelimeler hatalı sayılmayacaktır:
                       - Özel isimler (örneğin Hakan, İstanbul) ve özel karakterler (noktalama işaretleri, semboller) hata olarak kabul edilmemelidir.

                    3. Açıklamalar:
                        - Hata türleri belirtmeden, sadece bağlamdaki yanlışlıklar ve nedenlerini açıklayın. Her bir hata için doğru kullanımın ne olması gerektiğini belirtin..
                        - Örnekler:
                          - 'annesi ile' ifadesi bağlam hatası içeriyor; doğru kullanım 'annesiyle' olmalıdır.
                          - 'çok su içmiş midesindeki' ifadesi gereksiz yere tekrar edilmiştir.
                          - 'kalmıştı derslerini' kelimesi yanlış kullanılmıştır; doğru kullanım 'kalmıştı ve derslerini' olmalıdır.

                    4. Transkripte edilmiş metinde Hata türlerini ve sadece sayılarını 'Hatalar:' başlığı altında şu şekilde belirtmeni istiyorum.
                        - Fazladan eklenmiş kelimeler, bağlaçlar, zaman ve gereklilik kipleri, heceler 'addition_errors' kategorisine girer.
                            Örnek: 'Hakan çok su içmişti' yerine 'Hakan su içmişti'.
                            Örnek: 'balığı' yerine 'balı'.

                        - Eksik olan kelimeler, bağlaçlar, zaman ve gereklilik kipleri, heceler 'omission_errors' kategorisinde ele alınır.
                            Örnek: 'Hakan evde yalnız kalmıştı derslerini' yerine 'Hakan evde yalnız kalmıştı ve derslerini'.
                            Örnek: 'reçelde' yerine 'reçelden'

                        - Tersine çevrilmiş kelimeler kullanılmışsa 'reversal_errors' kategorisinde ele alınır.
                            Örnek: 'Ev' yerine 'Ve' kullanılması gibi.
                            Örnek: 'Patik' yerine 'Kitap' gibi.

                        - Tekrar edilmiş kelimeler ve heceler 'repetition_errors' kategorisinde ele alınır.
                            Örnek: 'aklına reçel reçel yemek gelmişti' yerine 'aklına reçel yemek gelmişti'.
                            Örnek: 'sıcak sıcak çay' yerine 'sıcak çay'

                        - Yukarıdaki kategorilere uymayan hatalar 'other_errors' kategorisine eklenmelidir.
                            Örnek: Yanlış yazım hataları 'bacak' yerine 'böcek'

                    Örnek Çıktı:
                    Metin: 'bir hafta önce {annesi ile} ablası çarşıya çıkmışlardı hakan evde yalnız kalmıştı derslerini bitirdikten sonra aklına {reçel} {reçel} yemek gelmişti.'

                    Açıklama:
                    1. 'annesi ile' ifadesi bağlam hatası içeriyor; doğru kullanım 'annesiyle' olmalıdır.
                    2. 'reçel' kelimesi iki kere tekrar edilmiştir.

                    Hatalar:
                    - addition_errors: 1
                    - omission_errors: 0
                    - reversal_errors: 1
                    - repetition_errors: 2
                    - other_errors: 0

                    Bu çıktıyı her bir hata için aynı şekilde üret. Ek olarak, tespit edilen diğer anlam veya dilbilgisel hataları da belirtin."

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

        $totalTokens = $analysisText['usage']['total_tokens'] ?? 0;

        // bir token yaklaşık olarak 4 karaktere denk gelir ve bir token ortalama olarak 0.75 kelimeye karşılık gelir
        $wordCount = $totalTokens * 0.75;

        // Okuma süresi dakikada 200 kelime
        $readingSpeed = $wordCount / 200;

        // Ondalıklı biçimlendir Örn; 4.7999997 --> 4.80
        $readingSpeed = number_format($readingSpeed, 2);

        $content = $analysisText['choices'][0]['message']['content'] ?? null;

        preg_match('/Metin:\s*(.*?)Açıklama:/s', $content, $part1);
        preg_match('/Açıklama:\s*(.*?)Hatalar:/s', $content, $part2);
        preg_match('/Hatalar:\s*(.*?)$/s', $content, $part3);

        $text = isset($part1[1]) ? trim($part1[1]) : '';
        $description = isset($part2[1]) ? trim($part2[1]) : '';
        $errors = isset($part3[1]) ? trim($part3[1]) : '';

        preg_match_all('/([a-z_]+_errors): (\d+)/', $errors, $errorMatches);

        $errorsArray = [];
        for ($i = 0; $i < count($errorMatches[1]); $i++) {
            $errorsArray[$errorMatches[1][$i]] = (int)$errorMatches[2][$i];
        }

        return [
            'original_text' => $originalText,
            'text' => $text,
            'description' => $description,
            'errors' => $errorsArray,
            'reading_speed' => $readingSpeed,
        ];
    }
}
