<?php

declare(strict_types=1);

namespace app\models;

use yii2swoole\web\UploadedFile;
use yii\base\Model;

/**
 * Form model demonstrating Yii2 FileValidator integration with coroutine-safe Web\UploadedFile.
 */
class UploadForm extends Model
{
    public const SCENARIO_SINGLE = 'single';
    public const SCENARIO_MULTIPLE = 'multiple';

    public ?UploadedFile $file = null;

    /** @var UploadedFile[] */
    public array $files = [];

    public ?string $note = null;

    public function rules(): array
    {
        return [
            [['note'], 'string', 'max' => 255],
            [
                ['file'],
                'file',
                'skipOnEmpty' => false,
                'maxSize' => 5 * 1024 * 1024,
                'on' => self::SCENARIO_SINGLE,
            ],
            [
                ['files'],
                'file',
                'skipOnEmpty' => false,
                'maxFiles' => 5,
                'maxSize' => 5 * 1024 * 1024,
                'on' => self::SCENARIO_MULTIPLE,
            ],
        ];
    }
}
