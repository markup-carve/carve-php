<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer;

use LogicException;

/**
 * Writes each output line once, carrying the state of enclosing indentation.
 *
 * @internal
 */
final class HtmlLayoutWriter
{
    private ?HtmlIndentScope $scope = null;

    /**
     * Tag state is bit 0; preformatted state is bit 1.
     *
     * @var array<int, \MarkupCarve\Carve\Renderer\HtmlIndentGroup|null>
     */
    private array $groups = [null, null, null, null];

    private int $lineSpaces = 0;

    private bool $lineStarted = false;

    private string $line = '';

    /**
     * @var list<string>
     */
    private array $parts = [];

    public function pushIndent(int $spaces): void
    {
        $group = $this->groups[0] ??= new HtmlIndentGroup();
        $group->spaces += $spaces;
        $this->scope = new HtmlIndentScope($this->scope, $spaces, $group);
    }

    public function popIndent(): void
    {
        $scope = $this->scope;
        if ($scope === null) {
            throw new LogicException('No HTML indentation scope to close');
        }
        $group = $scope->group->root();
        $group->spaces -= $scope->spaces;
        $this->scope = $scope->parent;
    }

    public function write(string $html): void
    {
        $at = 0;
        $length = strlen($html);
        while ($at < $length) {
            if (
                !$this->lineStarted
                && ($this->groups[0]->spaces ?? 0) === 0
                && ($this->groups[1]->spaces ?? 0) === 0
                && (($this->groups[2]->spaces ?? 0) + ($this->groups[3]->spaces ?? 0)) > 0
            ) {
                $close = strpos($html, '</pre>', $at);
                $prefix = $close === false ? substr($html, $at) : substr($html, $at, $close - $at);
                $break = strrpos($prefix, "\n");
                if ($break !== false) {
                    $this->parts[] = $this->line . substr($prefix, 0, $break + 1);
                    $this->line = '';
                    $this->lineStarted = false;
                    $at += $break + 1;

                    continue;
                }
            }
            if (!$this->lineStarted) {
                $this->lineStarted = true;
                $this->lineSpaces = $this->groups[0]->spaces ?? 0;
            }
            $end = strpos($html, "\n", $at);
            if ($end === false) {
                $this->line .= substr($html, $at);

                break;
            }
            $this->line .= substr($html, $at, $end - $at);
            $this->flushLine(true);
            $at = $end + 1;
        }
    }

    public function finish(): string
    {
        if ($this->lineStarted) {
            $this->flushLine(false);
        }

        return implode('', $this->parts);
    }

    private function flushLine(bool $newline): void
    {
        $line = $this->line;
        $spaces = $line === '' ? 0 : $this->lineSpaces;
        // Added spaces cannot change tag or preformatted transitions, so each
        // line applies the same transition to all enclosing scopes. Merge
        // equal states instead of visiting every ancestor for every raw line.
        $opensPre = str_contains($line, '<pre') && !str_contains($line, '</pre>');
        $closesPre = str_contains($line, '</pre>');
        $endsInTag = HtmlLineState::endsInsideTag($line, false);
        $endsInOpenTag = HtmlLineState::endsInsideTag($line, true);
        $next = [null, null, null, null];
        foreach ($this->groups as $state => $group) {
            if ($group === null || $group->spaces === 0) {
                continue;
            }
            if (($state & 2) !== 0) {
                $target = $closesPre ? $state & 1 : $state;
            } else {
                $target = (int)(($state & 1) !== 0 ? $endsInOpenTag : $endsInTag)
                | ($opensPre ? 2 : 0);
            }
            $next[$target] = $next[$target] === null ? $group : $next[$target]->merge($group);
        }
        $this->groups = $next;
        $this->parts[] = str_repeat(' ', $spaces) . $line . ($newline ? "\n" : '');
        $this->line = '';
        $this->lineStarted = false;
    }
}
