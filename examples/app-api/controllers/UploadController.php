<?php

declare(strict_types=1);

namespace app\controllers;

use app\models\UploadForm;
use yii2swoole\web\UploadedFile;
use Swoole\Coroutine;
use Yii;
use yii\helpers\FileHelper;
use yii\web\BadRequestHttpException;
use yii\web\Controller;
use yii\web\MethodNotAllowedHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Demonstrates coroutine-safe file upload & download using yii2swoole\web\UploadedFile.
 */
class UploadController extends Controller
{
    /**
     * Lists uploaded files currently stored in @runtime/uploads.
     */
    public function actionIndex(): Response
    {
        $dir = $this->getUploadDir();
        $files = [];

        if (is_dir($dir)) {
            $entries = scandir($dir) ?: [];
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $fullPath = $dir . DIRECTORY_SEPARATOR . $entry;
                if (is_file($fullPath)) {
                    $files[] = [
                        'stored_name' => $entry,
                        'size' => filesize($fullPath),
                        'modified_at' => date(DATE_ATOM, (int) filemtime($fullPath)),
                        'download_url' => '/upload/download?name=' . rawurlencode($entry),
                    ];
                }
            }
        }

        return $this->asJson([
            'upload_dir' => $dir,
            'count' => count($files),
            'files' => $files,
            'endpoints' => [
                'POST /upload/single' => 'Single file upload (field: file, optional: note)',
                'POST /upload/multiple' => 'Multiple files upload (field: files[])',
                'POST /upload/model' => 'Yii2 Model + FileValidator upload (UploadForm[file] or UploadForm[files][])',
                'GET /upload/download?name=<stored_name>' => 'Download an uploaded file via Web\\Response::sendFile()',
                'POST /upload/cleanup' => 'Delete all uploaded demo files',
            ],
        ]);
    }

    /**
     * Single file upload via UploadedFile::getInstanceByName('file').
     */
    public function actionSingle(): Response
    {
        $this->requirePost();

        $file = UploadedFile::getInstanceByName('file');
        if ($file === null) {
            throw new BadRequestHttpException('Missing uploaded file field "file".');
        }

        $saved = $this->persistUploadedFile($file);

        return $this->asJson([
            'status' => 'ok',
            'coroutine_id' => Coroutine::getCid(),
            'note' => Yii::$app->request->post('note'),
            'file' => $saved,
        ]);
    }

    /**
     * Multiple files upload via UploadedFile::getInstancesByName('files').
     */
    public function actionMultiple(): Response
    {
        $this->requirePost();

        $files = UploadedFile::getInstancesByName('files');
        if ($files === []) {
            throw new BadRequestHttpException('Missing uploaded files field "files[]".');
        }

        $saved = [];
        foreach ($files as $file) {
            $saved[] = $this->persistUploadedFile($file);
        }

        return $this->asJson([
            'status' => 'ok',
            'coroutine_id' => Coroutine::getCid(),
            'count' => count($saved),
            'files' => $saved,
        ]);
    }

    /**
     * Model-bound upload using UploadForm + Yii2 FileValidator.
     * Supports both single (UploadForm[file]) and multiple (UploadForm[files][]).
     */
    public function actionModel(): Response
    {
        $this->requirePost();

        $model = new UploadForm();
        $model->load(Yii::$app->request->post());

        $multipleFiles = UploadedFile::getInstances($model, 'files');
        if ($multipleFiles !== []) {
            $model->scenario = UploadForm::SCENARIO_MULTIPLE;
            $model->files = $multipleFiles;
        } else {
            $model->scenario = UploadForm::SCENARIO_SINGLE;
            $model->file = UploadedFile::getInstance($model, 'file');
        }

        if (!$model->validate()) {
            Yii::$app->response->setStatusCode(422);
            return $this->asJson([
                'status' => 'validation_failed',
                'scenario' => $model->scenario,
                'errors' => $model->getErrors(),
            ]);
        }

        $targets = $model->scenario === UploadForm::SCENARIO_MULTIPLE
            ? $model->files
            : ($model->file !== null ? [$model->file] : []);

        $saved = [];
        foreach ($targets as $file) {
            $saved[] = $this->persistUploadedFile($file);
        }

        return $this->asJson([
            'status' => 'ok',
            'scenario' => $model->scenario,
            'coroutine_id' => Coroutine::getCid(),
            'note' => $model->note,
            'count' => count($saved),
            'files' => $saved,
        ]);
    }

    /**
     * Downloads a previously uploaded file via Web\Response::sendFile().
     */
    public function actionDownload(string $name): Response
    {
        $safeName = basename($name);
        if ($safeName === '' || $safeName !== $name) {
            throw new BadRequestHttpException('Invalid file name.');
        }

        $path = $this->getUploadDir() . DIRECTORY_SEPARATOR . $safeName;
        if (!is_file($path)) {
            throw new NotFoundHttpException("Uploaded file \"{$safeName}\" not found.");
        }

        return Yii::$app->response->sendFile($path, $safeName);
    }

    /**
     * Removes all demo uploaded files from @runtime/uploads.
     */
    public function actionCleanup(): Response
    {
        $this->requirePost();

        $dir = $this->getUploadDir();
        $deleted = 0;

        if (is_dir($dir)) {
            $entries = scandir($dir) ?: [];
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $fullPath = $dir . DIRECTORY_SEPARATOR . $entry;
                if (is_file($fullPath) && @unlink($fullPath)) {
                    $deleted++;
                }
            }
        }

        return $this->asJson([
            'status' => 'ok',
            'deleted' => $deleted,
        ]);
    }

    private function requirePost(): void
    {
        if (!Yii::$app->request->isPost) {
            throw new MethodNotAllowedHttpException('Only POST requests are allowed.');
        }
    }

    private function getUploadDir(): string
    {
        return Yii::getAlias('@runtime/uploads');
    }

    /**
     * @return array{original_name:string,stored_name:string,extension:string,type:string,size:int,sha256:string,download_url:string}
     */
    private function persistUploadedFile(UploadedFile $file): array
    {
        $dir = $this->getUploadDir();
        FileHelper::createDirectory($dir);

        $sanitized = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($file->name)) ?: 'upload.bin';
        $storedName = bin2hex(random_bytes(8)) . '_' . $sanitized;
        $targetPath = $dir . DIRECTORY_SEPARATOR . $storedName;

        if (!$file->saveAs($targetPath)) {
            throw new BadRequestHttpException("Failed to save uploaded file \"{$file->name}\" (error={$file->error}).");
        }

        return [
            'original_name' => $file->name,
            'stored_name' => $storedName,
            'extension' => $file->extension,
            'type' => $file->type,
            'size' => $file->size,
            'sha256' => hash_file('sha256', $targetPath) ?: '',
            'download_url' => '/upload/download?name=' . rawurlencode($storedName),
        ];
    }
}
