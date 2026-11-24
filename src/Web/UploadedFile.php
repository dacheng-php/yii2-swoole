<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Web;

use Swoole\Coroutine;
use Yii;
use yii\helpers\Html;
use yii\web\UploadedFile as BaseUploadedFile;

/**
 * Coroutine-safe replacement for yii\web\UploadedFile.
 *
 * Reads uploaded files from Web\Request::getUploadedFiles() instead of PHP's
 * global $_FILES, caches parsed instances in Swoole coroutine context instead of
 * UploadedFile::$_files static property, and saves files without relying on
 * SAPI-only is_uploaded_file()/move_uploaded_file().
 */
class UploadedFile extends BaseUploadedFile
{
    private const CONTEXT_FILES_KEY = '__yii2SwooleUploadedFiles';

    public static function getInstance($model, $attribute)
    {
        $name = Html::getInputName($model, $attribute);

        return static::getInstanceByName($name);
    }

    public static function getInstances($model, $attribute)
    {
        $name = Html::getInputName($model, $attribute);

        return static::getInstancesByName($name);
    }

    public static function getInstanceByName($name)
    {
        $files = static::loadCoroutineFiles();

        return $files[$name] ?? null;
    }

    public static function getInstancesByName($name)
    {
        $files = static::loadCoroutineFiles();
        if (isset($files[$name])) {
            return [$files[$name]];
        }

        $results = [];
        foreach ($files as $key => $file) {
            if (str_starts_with($key, "{$name}[")) {
                $results[] = $file;
            }
        }

        return $results;
    }

    public static function reset(): void
    {
        if (Coroutine::getCid() >= 0) {
            unset(Coroutine::getContext()[self::CONTEXT_FILES_KEY]);
        }
    }

    /**
     * Saves the uploaded file in Swoole without SAPI move_uploaded_file() restriction.
     */
    public function saveAs($file, $deleteTempFile = true): bool
    {
        if ($this->error !== UPLOAD_ERR_OK || !is_file($this->tempName)) {
            return false;
        }

        $targetFile = Yii::getAlias($file);

        if ($deleteTempFile) {
            if (@rename($this->tempName, $targetFile)) {
                return true;
            }
            if (@copy($this->tempName, $targetFile)) {
                @unlink($this->tempName);
                return true;
            }

            return false;
        }

        return @copy($this->tempName, $targetFile);
    }

    /**
     * @return array<string, static>
     */
    private static function loadCoroutineFiles(): array
    {
        $inCoroutine = Coroutine::getCid() >= 0;
        if ($inCoroutine) {
            $context = Coroutine::getContext();
            if (isset($context[self::CONTEXT_FILES_KEY])) {
                return $context[self::CONTEXT_FILES_KEY];
            }
        }

        $request = Yii::$app?->getRequest();
        $rawFiles = $request instanceof Request ? $request->getUploadedFiles() : [];

        $files = [];
        foreach ($rawFiles as $class => $info) {
            if (!is_array($info) || !isset($info['name'])) {
                continue;
            }
            self::collectFilesRecursive(
                $files,
                (string) $class,
                $info['name'],
                $info['tmp_name'] ?? '',
                $info['type'] ?? '',
                $info['size'] ?? 0,
                $info['error'] ?? UPLOAD_ERR_NO_FILE
            );
        }

        if ($inCoroutine) {
            Coroutine::getContext()[self::CONTEXT_FILES_KEY] = $files;
        }

        return $files;
    }

    /**
     * @param array<string, static> $files
     */
    private static function collectFilesRecursive(
        array &$files,
        string $key,
        mixed $names,
        mixed $tempNames,
        mixed $types,
        mixed $sizes,
        mixed $errors
    ): void {
        if (is_array($names)) {
            foreach ($names as $i => $name) {
                self::collectFilesRecursive(
                    $files,
                    "{$key}[{$i}]",
                    $name,
                    $tempNames[$i] ?? '',
                    $types[$i] ?? '',
                    $sizes[$i] ?? 0,
                    $errors[$i] ?? UPLOAD_ERR_NO_FILE
                );
            }
            return;
        }

        if ((int) $errors !== UPLOAD_ERR_NO_FILE) {
            $files[$key] = new static([
                'name' => (string) $names,
                'tempName' => (string) $tempNames,
                'type' => (string) $types,
                'size' => (int) $sizes,
                'error' => (int) $errors,
            ]);
        }
    }
}
