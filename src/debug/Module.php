<?php

declare(strict_types=1);

namespace yii2swoole\debug;

use yii2swoole\coroutine\Telemetry;
use yii2swoole\debug\controllers\DefaultController;
use yii2swoole\debug\panels\DbPanel;
use yii2swoole\debug\panels\EventPanel;
use yii2swoole\debug\panels\MailPanel;
use yii2swoole\debug\panels\ProfilingPanel;
use yii2swoole\debug\panels\RequestPanel;
use yii2swoole\debug\panels\SwoolePanel;
use yii2swoole\debug\panels\TimelinePanel;
use yii2swoole\debug\panels\UserPanel;
use yii2swoole\web\RequestContext;
use ReflectionClass;
use Swoole\Coroutine;
use Yii;
use yii\base\Application;
use yii\debug\Module as BaseDebugModule;
use yii\debug\Panel;
use yii\helpers\Html;
use yii\helpers\Url;
use yii\web\ForbiddenHttpException;
use yii\web\Response;
use yii\web\View;

/**
 * Coroutine-safe Yii2 Debug Module for Swoole 6 resident workers.
 *
 * Key adaptations over yiisoft/yii2-debug:
 * 1. Uses a coroutine-isolated LogTarget implementing Log\CoroutineTargetInterface.
 * 2. Never mutates shared log target $enabled flags in beforeAction(); mutes only the current coroutine.
 * 3. Treats $this->panels as immutable prototypes and clones per-coroutine panel sets for every
 *    capture/replay so concurrent requests and debugger views never contaminate each other.
 * 4. Computes request duration from Web\RequestContext instead of process-wide YII_BEGIN_TIME.
 * 5. Registers SwoolePanel by default to expose coroutine, pool, Redis/Session, and worker telemetry.
 */
class Module extends BaseDebugModule
{
    private const CONTEXT_PANELS_KEY = '__yii2SwooleDebugPanels';
    private const CONTEXT_MUTED_KEY = '__yii2SwooleDebugMuted';

    /** @var string */
    public $controllerNamespace = 'yii2swoole\debug\controllers';

    /** @var LogTarget|array<string, mixed>|string */
    public $logTarget = LogTarget::class;

    /**
     * Whether to enable the EventPanel by default (capturing every Yii event via Event::on('*', '*')).
     * Safe under concurrency when enabled, but adds per-event overhead during development.
     */
    public bool $enableEventPanel = true;

    private ?string $officialViewPath = null;

    /**
     * Immutable worker-scoped Panel prototypes built once in initPanels().
     *
     * @var array<string, Panel>
     */
    private array $panelPrototypes = [];

    public function init(): void
    {
        if (Yii::getAlias('@yii2swoole', false) === false) {
            Yii::setAlias('@yii2swoole', dirname(__DIR__));
        }

        parent::init();
    }

    public function getControllerPath(): string
    {
        return __DIR__ . DIRECTORY_SEPARATOR . 'controllers';
    }

    protected function initPanels(): void
    {
        parent::initPanels();
        $this->panelPrototypes = $this->panels;

        // Unset mutable parent $panels so every access to $module->panels (including inside
        // yii\debug\models\timeline\Svg and official views) transparently resolves to the
        // current coroutine's cloned panel set.
        unset($this->panels);
    }

    public function __get($name)
    {
        if ($name === 'panels') {
            return $this->getCoroutinePanels();
        }

        return parent::__get($name);
    }

    public function __set($name, $value): void
    {
        if ($name === 'panels') {
            if (is_array($value)) {
                $this->setCoroutinePanels($value);
            }
            return;
        }

        parent::__set($name, $value);
    }

    public function __isset($name): bool
    {
        if ($name === 'panels') {
            return true;
        }

        return parent::__isset($name);
    }

    /**
     * @return array<string, Panel>
     */
    public function getPanelPrototypes(): array
    {
        return $this->panelPrototypes;
    }

    public function getViewPath(): string
    {
        if ($this->officialViewPath === null) {
            $ref = new ReflectionClass(BaseDebugModule::class);
            $this->officialViewPath = dirname((string) $ref->getFileName()) . DIRECTORY_SEPARATOR . 'views';
        }

        return $this->officialViewPath;
    }

    public function bootstrap($app): void
    {
        Telemetry::enable();

        if (is_array($this->logTarget)) {
            if (!isset($this->logTarget['class'])) {
                $this->logTarget['class'] = LogTarget::class;
            }
            $this->logTarget = Yii::createObject($this->logTarget, [$this]);
        } elseif (is_string($this->logTarget)) {
            $this->logTarget = Yii::createObject($this->logTarget, [$this]);
        }

        $app->getLog()->targets['debug'] = $this->logTarget;

        // Attach per-request hooks; response and view are coroutine-isolated in Web\Application,
        // so these handlers live only for the current request and never accumulate across requests.
        $app->on(Application::EVENT_BEFORE_REQUEST, function () use ($app): void {
            $this->unmuteCurrentCoroutine();
            $this->clearCoroutinePanels();
            $app->getResponse()->on(Response::EVENT_AFTER_PREPARE, [$this, 'setDebugHeaders']);
        });

        $app->on(Application::EVENT_BEFORE_ACTION, function () use ($app): void {
            $app->getView()->on(View::EVENT_END_BODY, [$this, 'renderToolbar']);
        });

        $app->getUrlManager()->addRules([
            [
                'class' => $this->urlRuleClass,
                'route' => $this->getUniqueId(),
                'pattern' => $this->getUniqueId(),
                'normalizer' => false,
                'suffix' => false,
            ],
            [
                'class' => $this->urlRuleClass,
                'route' => $this->getUniqueId() . '/<controller>/<action>',
                'pattern' => $this->getUniqueId() . '/<controller:[\w\-]+>/<action:[\w\-]+>',
                'normalizer' => false,
                'suffix' => false,
            ],
        ], false);
    }

    public function beforeAction($action): bool
    {
        // IMPORTANT: Do NOT set $target->enabled = false on shared log targets!
        // Mute only the current coroutine so concurrent business requests remain unaffected.
        if (!$this->enableDebugLogs) {
            $this->muteCurrentCoroutine();
        }

        if (!\yii\base\Module::beforeAction($action)) {
            return false;
        }

        Yii::$app->getView()->off(View::EVENT_END_BODY, [$this, 'renderToolbar']);
        Yii::$app->getResponse()->off(Response::EVENT_AFTER_PREPARE, [$this, 'setDebugHeaders']);

        if ($this->checkAccess($action)) {
            $this->resetGlobalSettings();

            return true;
        }

        if ($action->id === 'toolbar') {
            return false;
        }

        throw new ForbiddenHttpException('You are not allowed to access this page.');
    }

    public function setDebugHeaders($event): void
    {
        if ($this->isCurrentCoroutineMuted() || !$this->checkAccess()) {
            return;
        }

        $tag = $this->logTarget->tag;
        $url = Url::toRoute([
            '/' . $this->getUniqueId() . '/default/view',
            'tag' => $tag,
        ]);

        $ctx = RequestContext::current();
        $durationMs = $ctx !== null
            ? $ctx->elapsedMs()
            : max(0.0, (microtime(true) - YII_BEGIN_TIME) * 1000.0);

        $event->sender->getHeaders()
            ->set('X-Debug-Tag', $tag)
            ->set('X-Debug-Duration', number_format($durationMs + 1.0))
            ->set('X-Debug-Link', $url);
    }

    public function getToolbarHtml(): string
    {
        $url = Url::toRoute([
            '/' . $this->getUniqueId() . '/default/toolbar',
            'tag' => $this->logTarget->tag,
        ]);

        // Avoid mutating $this->skipAjaxRequestUrl in place across resident requests.
        $skipUrls = [];
        if (!empty($this->skipAjaxRequestUrl)) {
            foreach ($this->skipAjaxRequestUrl as $key => $route) {
                $skipUrls[$key] = Url::to($route);
            }
        }

        return '<div id="yii-debug-toolbar" data-url="' . Html::encode($url)
            . '" data-skip-urls="' . htmlspecialchars((string) json_encode($skipUrls), ENT_QUOTES, 'UTF-8')
            . '" style="display:none" class="yii-debug-toolbar-bottom"></div>';
    }

    public function renderToolbar($event): void
    {
        if ($this->isCurrentCoroutineMuted() || !$this->checkAccess() || Yii::$app->getRequest()->getIsAjax()) {
            return;
        }

        /** @var View $view */
        $view = $event->sender;
        $debugSrcDir = dirname($this->getViewPath());

        echo $view->renderDynamic('return Yii::$app->getModule("' . $this->getUniqueId() . '")->getToolbarHtml();');
        echo '<style>' . $view->renderPhpFile($debugSrcDir . '/assets/css/toolbar.css') . '</style>';
        echo '<script>' . $view->renderPhpFile($debugSrcDir . '/assets/js/toolbar.js') . '</script>';
    }

    /**
     * Returns the coroutine-local cloned panel instances for the current request/replay.
     *
     * @return array<string, Panel>
     */
    public function getCoroutinePanels(): array
    {
        if (!self::inCoroutine()) {
            return $this->panelPrototypes;
        }

        $context = Coroutine::getContext();
        $key = $this->getPanelsContextKey();
        if (isset($context[$key]) && is_array($context[$key])) {
            return $context[$key];
        }

        $cloned = [];
        foreach ($this->panelPrototypes as $id => $panel) {
            $cloned[$id] = clone $panel;
        }
        $context[$key] = $cloned;

        return $cloned;
    }

    /**
     * Replaces the current coroutine's panel map (e.g. after loadTagToPanels removes missing panels).
     *
     * @param array<string, Panel> $panels
     */
    public function setCoroutinePanels(array $panels): void
    {
        if (!self::inCoroutine()) {
            $this->panelPrototypes = $panels;
            return;
        }

        Coroutine::getContext()[$this->getPanelsContextKey()] = $panels;
    }

    public function getCoroutinePanel(string $id): ?Panel
    {
        $panels = $this->getCoroutinePanels();

        return $panels[$id] ?? null;
    }

    public function clearCoroutinePanels(): void
    {
        if (!self::inCoroutine()) {
            return;
        }

        unset(Coroutine::getContext()[$this->getPanelsContextKey()]);
    }

    public function muteCurrentCoroutine(): void
    {
        if (self::inCoroutine()) {
            Coroutine::getContext()[$this->getMutedContextKey()] = true;
        }
    }

    public function unmuteCurrentCoroutine(): void
    {
        if (self::inCoroutine()) {
            unset(Coroutine::getContext()[$this->getMutedContextKey()]);
        }
    }

    public function isCurrentCoroutineMuted(): bool
    {
        if (!self::inCoroutine()) {
            return false;
        }

        return !empty(Coroutine::getContext()[$this->getMutedContextKey()]);
    }

    protected function corePanels(): array
    {
        $corePanels = parent::corePanels();

        $corePanels['profiling'] = ['class' => ProfilingPanel::class];
        $corePanels['timeline'] = ['class' => TimelinePanel::class];
        $corePanels['db'] = ['class' => DbPanel::class];
        $corePanels['mail'] = ['class' => MailPanel::class];

        if ($this->enableEventPanel) {
            $corePanels['event'] = ['class' => EventPanel::class];
        } else {
            unset($corePanels['event']);
        }

        if (Yii::$app instanceof \yii\web\Application) {
            $corePanels['request'] = ['class' => RequestPanel::class];
            $corePanels['user'] = ['class' => UserPanel::class];
        }

        $corePanels['swoole'] = ['class' => SwoolePanel::class];

        return $corePanels;
    }

    private function getPanelsContextKey(): string
    {
        return self::CONTEXT_PANELS_KEY . ':' . spl_object_id($this);
    }

    private function getMutedContextKey(): string
    {
        return self::CONTEXT_MUTED_KEY . ':' . spl_object_id($this);
    }

    private static function inCoroutine(): bool
    {
        return extension_loaded('swoole') && Coroutine::getCid() >= 0;
    }
}
