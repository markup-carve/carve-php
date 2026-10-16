<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Ast\AstEnvelope;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Exception\AstDecodeException;
use MarkupCarve\Carve\Exception\AstEnvelopeShapeException;
use MarkupCarve\Carve\Exception\AstEnvelopeVersionException;
use PHPUnit\Framework\TestCase;

class AstContractOneTwoTest extends TestCase
{
    public function testReferenceStatesSurviveTheWire(): void
    {
        $codec = new AstCodec();
        $converter = new CarveConverter();
        foreach (
            [
                ["[t][r]\n\n[r]:\n", 'link', true],
                ["![a][r]\n\n[r]:\n", 'image', true],
                ['[t][missing]', 'link', false],
                ['![a][missing]', 'image', false],
                ["[^n]: [t][r]\n\n[r]:\n\nSee [^n].\n", 'link', true],
            ] as [$source, $type, $resolved]
        ) {
            $parsed = $converter->parse($source);
            $wire = $codec->encode($parsed);
            $references = [];
            $walk = static function (array $node) use (&$walk, &$references, $type): void {
                if (($node['type'] ?? null) === $type) {
                    $references[] = $node;
                }
                foreach ($node['children'] ?? [] as $child) {
                    $walk($child);
                }
            };
            $walk($wire);
            self::assertNotEmpty($references, $source);
            foreach ($references as $reference) {
                self::assertSame('', $reference[$type === 'link' ? 'href' : 'src']);
                self::assertSame($resolved, $reference['resolved'] ?? false);
            }
            self::assertSame($converter->render($parsed), $converter->render($codec->decode($wire)), $source);
        }
    }

    public function testResolvedFieldIsTheSourceOfDecodedState(): void
    {
        $codec = new AstCodec();
        $converter = new CarveConverter();
        $wire = $codec->encode($converter->parse("[t][r]\n\n[r]:\n"));
        unset($wire['children'][0]['children'][0]['resolved']);
        $decoded = $codec->decode($wire);
        self::assertStringContainsString('[t][r]', $converter->render($decoded));
        self::assertArrayNotHasKey('resolved', $codec->encode($decoded)['children'][0]['children'][0]);
        $wire['children'][0]['children'][0]['resolved'] = false;
        $this->expectException(AstDecodeException::class);
        $codec->decode($wire);
    }

    public function testResolvedClaimNeedsAnEmptyReferenceDestination(): void
    {
        $codec = new AstCodec();
        $wire = $codec->encode((new CarveConverter())->parse("[t][r]\n\n[r]: /target\n"));
        $wire['children'][0]['children'][0]['resolved'] = true;
        $this->expectException(AstDecodeException::class);
        $this->expectExceptionMessage('link.resolved');
        $codec->decode($wire);
    }

    public function testCommentFormsAndDisagreement(): void
    {
        $codec = new AstCodec();
        $converter = new CarveConverter();
        foreach (
            [
                ["%%% hi\n%%%\n", 'block'],
                ["x %% hi\n", 'line'],
                ["x {% hi %} y\n", 'braced'],
            ] as [$source, $form]
        ) {
            $wire = $codec->encode($converter->parse($source));
            $comment =&$wire['children'][0];
            if ($form !== 'block') {
                $comment =&$comment['children'][1];
            }
            self::assertSame($form, $comment['form']);
            self::assertSame($form === 'block', $comment['block']);
            self::assertSame($form === 'braced', $comment['delimited'] ?? false);
            self::assertSame($converter->render($converter->parse($source)), $converter->render($codec->decode($wire)));
            unset($comment['form']);
            $codec->decode($wire);
            $comment['form'] = $form === 'line' ? 'braced' : 'line';
            try {
                $codec->decode($wire);
                self::fail('Expected a form disagreement');
            } catch (AstDecodeException $e) {
                self::assertStringContainsString('comment.form', $e->getMessage());
            }
            unset($comment);
        }
    }

    public function testEnvelopeAndBareRootVersions(): void
    {
        $codec = new AstCodec();
        $wire = $codec->encode((new CarveConverter())->parse('hi'));
        $envelope = new AstEnvelope($codec);
        foreach (['1.0', '1.1', '1.2'] as $version) {
            self::assertNotEmpty($codec->decode($wire + ['astVersion' => $version])->getChildren());
            self::assertNotEmpty($envelope->decode(['astVersion' => $version, 'document' => $wire])->getChildren());
        }
        foreach (['1.3', '2.0'] as $version) {
            foreach ([$wire + ['astVersion' => $version], ['astVersion' => $version, 'document' => $wire]] as $index => $payload) {
                try {
                    $index === 0 ? $codec->decode($payload) : $envelope->decode($payload);
                    self::fail('Expected a version refusal');
                } catch (AstEnvelopeVersionException $e) {
                    self::assertSame($version, $e->found);
                    self::assertSame('1.2', $e->implemented);
                }
            }
        }
        foreach (['01.2', '1.02', 'bad', 1.2] as $version) {
            try {
                $codec->decode($wire + ['astVersion' => $version]);
                self::fail('Expected a shape refusal');
            } catch (AstEnvelopeShapeException $e) {
                self::assertStringContainsString('astVersion', $e->getMessage());
            }
        }
        $this->expectException(AstDecodeException::class);
        $codec->decode($wire + ['other' => true]);
    }

    public function testCliPublishesBareRootVersion(): void
    {
        $process = proc_open([PHP_BINARY, 'bin/carve', '--json'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fwrite($pipes[0], 'hi');
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process));
        self::assertSame('1.2', json_decode((string)$output, true)['astVersion']);
    }
}
