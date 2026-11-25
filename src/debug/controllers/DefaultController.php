<?php

declare(strict_types=1);

namespace yii2swoole\debug\controllers;

use yii2swoole\debug\Module;
use Swoole\Coroutine;
use Yii;
use yii\debug\controllers\DefaultController as BaseDefaultController;
use yii\debug\models\search\Debug;
use yii\web\NotFoundHttpException;

/**
 * Coroutine-safe DefaultController for the Yii2 Debug Module.
 *
 * Fixes:
 * - Reads query params from Yii::$app->getRequest()->getQueryParams() instead of $_GET.
 * - Renders views with coroutine-local cloned panels instead of shared Module::$panels prototypes.
 * - Uses short non-blocking Coroutine::sleep(0.05) polling instead of 1-second blocking sleep().
 */
class DefaultController extends BaseDefaultController
{
    /** @var Module */
    public $module;

    public function actionIndex()
    {
        $searchModel = new Debug();
        $manifest = $this->getManifest();
        $dataProvider = $searchModel->search(Yii::$app->getRequest()->getQueryParams(), $manifest);

        $tags = array_keys($manifest);
        if ($tags === []) {
            throw new \RuntimeException('No debug data have been collected yet, try browsing the website first.');
        }

        $tag = (string) reset($tags);
        $this->loadData($tag);

        return $this->render('index', [
            'panels' => $this->getActivePanels(),
            'dataProvider' => $dataProvider,
            'searchModel' => $searchModel,
            'manifest' => $manifest,
        ]);
    }

    public function actionView($tag = null, $panel = null)
    {
        if ($tag === null) {
            $tags = array_keys($this->getManifest());
            $tag = reset($tags);
            if ($tag === false) {
                throw new NotFoundHttpException('No debug data have been collected yet.');
            }
        }

        $this->loadData((string) $tag);
        $panels = $this->getActivePanels();

        if ($panel !== null && isset($panels[$panel])) {
            $activePanel = $panels[$panel];
        } elseif (isset($panels[$this->module->defaultPanel])) {
            $activePanel = $panels[$this->module->defaultPanel];
        } else {
            $activePanel = reset($panels);
        }

        if ($activePanel === false) {
            throw new NotFoundHttpException('No debug panels available.');
        }

        if ($activePanel->hasError()) {
            Yii::$app->getErrorHandler()->handleException($activePanel->getError());
        }

        return $this->render('view', [
            'tag' => $tag,
            'summary' => $this->summary,
            'manifest' => $this->getManifest(),
            'panels' => $panels,
            'activePanel' => $activePanel,
        ]);
    }

    public function actionToolbar($tag)
    {
        // Fast polling up to ~1s (20 x 50ms) handles the brief window while the previous
        // coroutine finishes flushing its debug snapshot right after Response::send().
        $this->loadData((string) $tag, 20);

        return $this->renderPartial('toolbar', [
            'tag' => $tag,
            'panels' => $this->getActivePanels(),
            'position' => $this->module->toolbarPosition,
            'defaultHeight' => $this->module->defaultHeight,
        ]);
    }

    public function loadData($tag, $maxRetry = 0): void
    {
        $inCoroutine = extension_loaded('swoole') && Coroutine::getCid() >= 0;

        for ($retry = 0; $retry <= $maxRetry; ++$retry) {
            $manifest = $this->getManifest($retry > 0);
            if (isset($manifest[$tag])) {
                $data = $this->module->logTarget->loadTagToPanels($tag);
                $this->summary = $data['summary'] ?? [];

                return;
            }

            if ($retry < $maxRetry) {
                if ($inCoroutine) {
                    Coroutine::sleep(0.05);
                } else {
                    usleep(50_000);
                }
            }
        }

        throw new NotFoundHttpException("Unable to find debug data tagged with '{$tag}'.");
    }

    /**
     * @return array<string, \yii\debug\Panel>
     */
    private function getActivePanels(): array
    {
        return $this->module instanceof Module
            ? $this->module->getCoroutinePanels()
            : $this->module->panels;
    }
}
