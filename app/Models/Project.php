<?php

namespace App\Models;

use App\Services\Scanner\GithubSource;
use App\Services\Scanner\LockFileParser;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'name',
    'source_type',
    'repo',
    'branch',
    'path',
    'composer_json',
    'package_json',
    'composer_lock',
    'package_lock',
])]
class Project extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Sumber scan untuk proyek ini: file JSON tersimpan, atau diambil
     * dari repo GitHub tiap kali discan (termasuk lock file).
     *
     * @return array{composer_json: ?string, package_json: ?string, locks: array<string, string>, warnings: array<int, string>}
     */
    public function resolveSources(GithubSource $github): array
    {
        if ($this->source_type !== 'github') {
            $locks = [];

            foreach (LockFileParser::SUPPORTED as $filename) {
                $column = $filename === LockFileParser::COMPOSER ? 'composer_lock' : 'package_lock';
                $content = $this->getAttribute($column);

                if (is_string($content) && trim($content) !== '') {
                    $locks[$filename] = $content;
                }
            }

            return [
                'composer_json' => $this->composer_json,
                'package_json' => $this->package_json,
                'locks' => $locks,
                'warnings' => [],
            ];
        }

        $fetched = $github->fetchFiles(
            repo: $this->repo,
            branch: $this->branch,
            subdirectory: $this->path,
        );

        $locks = [];

        foreach (LockFileParser::SUPPORTED as $filename) {
            $content = $fetched['files'][$filename] ?? null;

            if (is_string($content) && trim($content) !== '') {
                $locks[$filename] = $content;
            }
        }

        return [
            'composer_json' => $fetched['files']['composer.json'] ?? null,
            'package_json' => $fetched['files']['package.json'] ?? null,
            'locks' => $locks,
            'warnings' => $fetched['warnings'],
        ];
    }
}
