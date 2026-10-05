<?php
// php tests/run.php — no framework needed.
require __DIR__ . '/../src/SayMakerException.php';
require __DIR__ . '/../src/SayMaker.php';

use SayMaker\SayMaker;
use SayMaker\SayMakerException;

function check(bool $ok, string $what): void { if (!$ok) { fwrite(STDERR, "FAIL $what\n"); exit(1); } }

$calls = [];
$fake = function (array $final) use (&$calls) { return function (string $url, array $body, string $key) use (&$calls, $final): array {
    $calls[] = $body;
    return ['code' => 0, 'data' => str_ends_with($url, '/api/ai/generate') ? ['id' => 'row1'] : $final];
}; };
$sm = new SayMaker('sk', 'https://t', $fake(['images' => ['m'], 'cleanImages' => ['c']]));
check($sm->generate('x', ['poll_every' => 0]) === 'c', 'clean first');
check($calls[0]['model'] === 'saymaker-image-v1' && $calls[1] === ['taskId' => 'row1'], 'image body');
$calls = [];
$sm = new SayMaker('sk', 'https://t', $fake(['videos' => ['v']]));
check($sm->generateVideo('x', ['duration' => 6, 'poll_every' => 0]) === 'v', 'video');
check($calls[0]['provider'] === 'vgenv' && $calls[0]['options']['duration'] === 6, 'video body');
check(SayMakerException::classify('insufficient credits')->kind === 'quota', 'quota');
check(SayMakerException::classify('Your current run is rendering. Plans on the pricing page')->kind === 'busy', 'busy');
putenv('SAYMAKER_API_KEY');
try { (new SayMaker())->generate('x'); check(false, 'needs key'); } catch (SayMakerException $e) { check($e->kind === 'auth', 'auth'); }
echo "all tests passed\n";
