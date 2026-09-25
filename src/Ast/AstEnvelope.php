<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Ast;

use MarkupCarve\Carve\Exception\AstEnvelopeExtensionException;
use MarkupCarve\Carve\Exception\AstEnvelopeShapeException;
use MarkupCarve\Carve\Exception\AstEnvelopeVersionException;
use MarkupCarve\Carve\Exception\AstEnvelopeVocabularyException;
use MarkupCarve\Carve\Node\Document;

/**
 * The versioned AST interchange envelope (PART 12 §34, [CARVE-P12-056]).
 *
 * The tree is strict-closed and carries no version, so a reader that cannot
 * read a payload has one answer for three different problems: a corrupt tree, a
 * vocabulary this build does not know, and a document needing an extension it
 * does not implement all arrive as one validation error. The envelope is what
 * lets the reader name which one it hit.
 *
 * The tree does not move. `document` is exactly what {@see AstCodec} writes and
 * reads, `carve --json` still writes it bare, and an in-memory handoff still
 * passes it bare. This is the storage-and-process-boundary surface beside that,
 * not a change to it.
 */
final class AstEnvelope
{
    /**
     * The interchange contract version this build implements.
     *
     * NOT the Carve language version, and it does not track it: the language is
     * versioned for authors, this is versioned for readers of a tree. A major
     * bump removes, renames or reinterprets; a minor bump adds.
     *
     * @var string
     */
    public const CONTRACT_VERSION = '1.0';

    /**
     * @var string
     */
    public const CORE_VOCABULARY = 'https://markup-carve.org/ast/core';

    /**
     * `^[1-9][0-9]*\.(0|[1-9][0-9]*)$`, from `ast-envelope-schema.json`.
     *
     * A leading zero is refused, so the `0.x`-reads-as-major convention the
     * rest of this org versions by never applies to the contract version.
     *
     * @var string
     */
    private const VERSION_PATTERN = '/^[1-9][0-9]*\.(?:0|[1-9][0-9]*)$/';

    /**
     * Every field the envelope schema names, and nothing else.
     *
     * @var array<string>
     */
    private const ENVELOPE_FIELDS = ['astVersion', 'vocabulary', 'extensions', 'document'];

    /**
     * Every field an extension entry may carry.
     *
     * @var array<string>
     */
    private const EXTENSION_FIELDS = ['id', 'version', 'required'];

    public function __construct(private readonly AstCodec $codec = new AstCodec())
    {
    }

    /**
     * Wrap a document for a storage or process boundary.
     *
     * The version emitted is always this build's own: re-emitting an ingested
     * `astVersion` republishes a claim the producer cannot keep, so there is
     * deliberately no option to override it.
     *
     * @param \MarkupCarve\Carve\Node\Document $document
     * @param string|null $vocabulary
     * @param array<int, array{id: string, version?: string, required?: bool}> $extensions
     *
     * @return array<string, mixed>
     */
    public function encode(Document $document, ?string $vocabulary = null, array $extensions = []): array
    {
        $envelope = ['astVersion' => self::CONTRACT_VERSION];
        if ($vocabulary !== null) {
            $envelope['vocabulary'] = $vocabulary;
        }
        if ($extensions !== []) {
            $envelope['extensions'] = array_values($extensions);
        }
        $envelope['document'] = $this->codec->encode($document);

        return $envelope;
    }

    /**
     * Read an envelope and return the document inside it.
     *
     * The checks run widest-first, so the caller hears about the contract
     * before the tree: a payload from a newer major is refused whatever its
     * tree looks like, and the tree is only walked once the reader has agreed
     * it can read this contract, this vocabulary and these extensions at all.
     *
     * @param array<string, mixed> $envelope
     * @param array<int, string> $extensions extension ids this reader implements
     * @param array<int, string> $vocabularies vocabularies this reader knows, beside the core one
     *
     * @throws \MarkupCarve\Carve\Exception\AstEnvelopeExtensionException
     * @throws \MarkupCarve\Carve\Exception\AstEnvelopeShapeException
     * @throws \MarkupCarve\Carve\Exception\AstEnvelopeVersionException
     * @throws \MarkupCarve\Carve\Exception\AstEnvelopeVocabularyException
     */
    public function decode(array $envelope, array $extensions = [], array $vocabularies = []): Document
    {
        // `additionalProperties: false` holds on the envelope as well as on
        // every node inside it: §34 closes it for the reason §11 closed the tree.
        foreach (array_keys($envelope) as $key) {
            if (!in_array($key, self::ENVELOPE_FIELDS, true)) {
                throw new AstEnvelopeShapeException(sprintf(
                    'it carries "%s", which the schema does not name',
                    (string)$key,
                ));
            }
        }

        $astVersion = $envelope['astVersion'] ?? null;
        if (!is_string($astVersion)) {
            throw new AstEnvelopeShapeException('"astVersion" is missing');
        }
        if (preg_match(self::VERSION_PATTERN, $astVersion) !== 1) {
            throw new AstEnvelopeShapeException(sprintf(
                '"astVersion" is "%s", which is not major.minor with no leading zero',
                $astVersion,
            ));
        }
        if (!array_key_exists('document', $envelope)) {
            throw new AstEnvelopeShapeException('"document" is missing');
        }

        // A higher major only. A LOWER one cannot arrive while this build
        // implements major 1, which the schema's pattern makes the lowest there
        // is, so there is nothing to decide and no rule invented for it.
        $major = (int)explode('.', $astVersion)[0];
        if ($major > (int)explode('.', self::CONTRACT_VERSION)[0]) {
            throw new AstEnvelopeVersionException($astVersion);
        }

        $vocabulary = $envelope['vocabulary'] ?? null;
        if ($vocabulary !== null) {
            if (!is_string($vocabulary)) {
                throw new AstEnvelopeShapeException('"vocabulary" is not a string');
            }
            if (!in_array($vocabulary, [self::CORE_VOCABULARY, ...$vocabularies], true)) {
                throw new AstEnvelopeVocabularyException($vocabulary);
            }
        }

        if (array_key_exists('extensions', $envelope)) {
            foreach ($this->readExtensions($envelope['extensions']) as $extension) {
                // Absent means true. An extension a reader may ignore without
                // misreading the document has to say so.
                if (($extension['required'] ?? true) === false) {
                    continue;
                }
                if (!in_array($extension['id'], $extensions, true)) {
                    throw new AstEnvelopeExtensionException($extension['id']);
                }
            }
        }

        $document = $this->readDocument($envelope['document']);

        // A higher MINOR needs no gate of its own: §34(b) makes it acceptable
        // exactly where every required extension is implemented, and the loop
        // above is that condition. A minor adds, so the tree is readable except
        // for what the additions carry.
        return $this->codec->decode($document);
    }

    /**
     * The tree, checked to be a JSON OBJECT rather than an array.
     *
     * A JSON array decodes to integer keys here, and the codec reads the tree
     * by name - so an array arrives as a document with no `type`, and the
     * refusal names the tree rather than the envelope that is actually wrong.
     *
     * @throws \MarkupCarve\Carve\Exception\AstEnvelopeShapeException
     *
     * @return array<string, mixed>
     */
    private function readDocument(mixed $value): array
    {
        if (!is_array($value)) {
            throw new AstEnvelopeShapeException('"document" is not an object');
        }

        $document = [];
        foreach ($value as $key => $entry) {
            if (!is_string($key)) {
                throw new AstEnvelopeShapeException('"document" is an array, not an object');
            }
            $document[$key] = $entry;
        }

        return $document;
    }

    /**
     * @throws \MarkupCarve\Carve\Exception\AstEnvelopeShapeException
     *
     * @return array<int, array{id: string, version?: string, required?: bool}>
     */
    private function readExtensions(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new AstEnvelopeShapeException('"extensions" is not an array');
        }

        $out = [];
        foreach ($value as $index => $entry) {
            if (!is_array($entry) || array_is_list($entry)) {
                throw new AstEnvelopeShapeException(sprintf('extension %d is not an object', $index));
            }
            foreach (array_keys($entry) as $key) {
                if (!in_array($key, self::EXTENSION_FIELDS, true)) {
                    throw new AstEnvelopeShapeException(sprintf(
                        'extension %d carries "%s", which the schema does not name',
                        $index,
                        (string)$key,
                    ));
                }
            }

            $id = $entry['id'] ?? null;
            if (!is_string($id)) {
                throw new AstEnvelopeShapeException(sprintf('extension %d has no "id"', $index));
            }
            $extension = ['id' => $id];

            if (array_key_exists('version', $entry)) {
                if (!is_string($entry['version'])) {
                    throw new AstEnvelopeShapeException(sprintf(
                        'extension "%s" has a non-string "version"',
                        $id,
                    ));
                }
                $extension['version'] = $entry['version'];
            }
            if (array_key_exists('required', $entry)) {
                if (!is_bool($entry['required'])) {
                    throw new AstEnvelopeShapeException(sprintf(
                        'extension "%s" has a non-boolean "required"',
                        $id,
                    ));
                }
                $extension['required'] = $entry['required'];
            }

            $out[] = $extension;
        }

        return $out;
    }
}
