<?php

declare(strict_types=1);

namespace SayMaker;

/**
 * Generate images and video on SayMaker (https://saymaker.ai).
 *
 *     $sm = new SayMaker\SayMaker(getenv('SAYMAKER_API_KEY'));
 *     $url = $sm->generate('a paper-cut layered mountain range at dusk');
 *     $clip = $sm->generateVideo('a fox trotting through snow', ['duration' => 6]);
 *
 * One API key runs the whole shelf on your own credits: Veo 3.1, Kling 3.0,
 * Seedance 2.0, MiniMax H3, Nano Banana 2, GPT Image 2.5, Seedream 5.0 and more.
 * Create a key at https://saymaker.ai/settings/apikeys.
 */
final class SayMaker
{
    public const BASE_URL = 'https://saymaker.ai';
    public const KEYS_URL = self::BASE_URL . '/settings/apikeys';
    /** The cheapest text-to-image model. */
    public const MODEL_IMAGE = 'saymaker-image-v1';
    /** The default edit model; it takes an input image. */
    public const MODEL_EDIT = 'nano-banana-2-lite';
    /** The video model a free account can run (480p or 768p, 4 to 15 seconds). */
    public const MODEL_VIDEO = 'minimax-h3-fast';

    /** Model id => the routing field the API expects next to it. Unlisted ids go as "kie". */
    public const MODELS = [
        'saymaker-image-v1' => 'kie', 'nano-banana-2-lite' => 'kie', 'nano-banana-2' => 'poyo',
        'nano-banana-pro' => 'poyo', 'gpt-image-2' => 'poyo', 'gpt-image-2-5-flare' => 'kie',
        'seedream-5-lite' => 'poyo', 'seedream-5-pro' => 'kie', 'qwen-image-3-pro' => 'kie',
        'grok-imagine-image-2-0' => 'poyo', 'minimax-h3-fast' => 'vgenv', 'minimax-h3' => 'poyo',
        'seedance-2' => 'kie', 'seedance-2-fast' => 'kie', 'seedance-2-5' => 'kie', 'veo-3-1' => 'kie',
        'kling-3-0' => 'kie', 'kling-3-0-turbo' => 'kie', 'wan-3-0' => 'kie', 'ltx-2-5-fast' => 'replicate',
    ];

    /** @var callable(string,array,string):array */
    private $transport;

    /**
     * @param string|null $apiKey  defaults to the SAYMAKER_API_KEY environment variable
     * @param callable|null $transport  fn(string $url, array $body, string $key): array, for tests
     */
    public function __construct(private ?string $apiKey = null, private string $baseUrl = self::BASE_URL, ?callable $transport = null)
    {
        $this->apiKey = $apiKey ?: (getenv('SAYMAKER_API_KEY') ?: null);
        $this->transport = $transport ?? [self::class, 'http'];
    }

    /**
     * Generate one image, or edit $opts['image_url'], and return its URL.
     *
     * @param array{model?:string,image_url?:string,aspect_ratio?:string,timeout?:int,poll_every?:float} $opts
     */
    public function generate(string $prompt, array $opts = []): string
    {
        $edit = isset($opts['image_url']);
        if ($edit) {
            self::checkUrl($opts['image_url']);
        }
        $options = ['size' => $opts['aspect_ratio'] ?? ($edit ? 'auto' : '1:1'), 'resolution' => '1K', 'n' => 1];
        if ($edit) {
            $options['image_input'] = [$opts['image_url']];
        }
        $model = $opts['model'] ?? ($edit ? self::MODEL_EDIT : self::MODEL_IMAGE);
        return $this->run('image', $edit ? 'image-to-image' : 'text-to-image', $model, $prompt, $options,
            $opts['timeout'] ?? 4500, $opts['poll_every'] ?? 5.0);
    }

    /**
     * Generate one clip, or animate $opts['image_url'] as the first frame, and return its URL.
     *
     * @param array{model?:string,image_url?:string,duration?:int,resolution?:string,aspect_ratio?:string,sound?:bool,timeout?:int,poll_every?:float} $opts
     */
    public function generateVideo(string $prompt, array $opts = []): string
    {
        $options = ['aspect_ratio' => $opts['aspect_ratio'] ?? '16:9', 'sound' => $opts['sound'] ?? true];
        if (isset($opts['image_url'])) {
            self::checkUrl($opts['image_url']);
            $options['image_input'] = [$opts['image_url']];
        }
        if (isset($opts['duration'])) {
            $options['duration'] = (int) $opts['duration'];
        }
        if (isset($opts['resolution'])) {
            $options['resolution'] = $opts['resolution'];
        }
        return $this->run('video', isset($opts['image_url']) ? 'image-to-video' : 'text-to-video',
            $opts['model'] ?? self::MODEL_VIDEO, $prompt, $options, $opts['timeout'] ?? 5400, $opts['poll_every'] ?? 10.0);
    }

    private static function checkUrl(string $u): void
    {
        if (!preg_match('#^https?://#', $u)) {
            throw new \InvalidArgumentException('image_url must be a public http(s) url');
        }
    }

    /** @return array<string,mixed> */
    public static function http(string $url, array $body, string $key): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $key, 'User-Agent: saymaker-php/0.1.0'],
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_SLASHES),
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new SayMakerException('other', $err ?: 'request failed');
        }
        $json = json_decode((string) $raw, true);
        return is_array($json) ? $json : [];
    }

    private function run(string $media, string $scene, string $model, string $prompt, array $options, int $timeout, float $pollEvery): string
    {
        if (trim($prompt) === '') {
            throw new \InvalidArgumentException('prompt is required');
        }
        if (!$this->apiKey) {
            throw new SayMakerException('auth', 'an API key is required; create one at ' . self::KEYS_URL);
        }
        $post = fn (string $path, array $body): array => ($this->transport)($this->baseUrl . $path, $body, $this->apiKey);
        $env = $post('/api/ai/generate', [
            'provider' => self::MODELS[$model] ?? 'kie', 'mediaType' => $media, 'model' => $model,
            'scene' => $scene, 'prompt' => $prompt, 'options' => $options,
        ]);
        // Refusals answer HTTP 200 with code -1; the status code alone reads as success.
        if (($env['code'] ?? null) !== 0) {
            throw SayMakerException::classify((string) ($env['message'] ?? 'request refused'), $env['data'] ?? null);
        }
        $data = $env['data'] ?? [];
        // A signed-out request is also a 200 with code 0; only `wall` tells it apart.
        if (!empty($data['wall'])) {
            throw new SayMakerException('auth', 'an API key is required; create one at ' . self::KEYS_URL);
        }
        $id = $data['id'] ?? null;
        if (!$id) {
            throw new SayMakerException('other', 'the service returned no task id');
        }
        // Free-account runs only reach the model on the poll that crosses the end of
        // the queue wait, so polling is what starts the work.
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            usleep((int) ($pollEvery * 1e6));
            try {
                $q = $post('/api/ai/query', ['taskId' => $id]);
            } catch (SayMakerException) {
                continue; // a dropped poll is not a failed generation
            }
            if (($q['code'] ?? null) !== 0) {
                continue;
            }
            $d = $q['data'] ?? [];
            foreach (['cleanImages', 'images', 'videos'] as $field) {
                if (!empty($d[$field][0])) {
                    return (string) $d[$field][0];
                }
            }
            if (in_array(strtolower((string) ($d['status'] ?? '')), ['failed', 'error'], true)) {
                throw new SayMakerException('rejected', 'the run failed; reword the prompt rather than retrying');
            }
        }
        throw new SayMakerException('timeout', 'deadline passed; the run may still finish in your SayMaker history');
    }
}
