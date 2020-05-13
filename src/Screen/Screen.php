<?php

declare(strict_types=1);

namespace Orchid\Screen;

use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Orchid\Platform\Http\Controllers\Controller;
use Orchid\Screen\Layouts\Base;
use ReflectionClass;
use ReflectionException;
use ReflectionParameter;
use Throwable;

/**
 * Class Screen.
 */
abstract class Screen extends Controller
{
    use Commander;

    /**
     * The number of predefined arguments in the route.
     *
     * Example: dashboard/my-screen/{method?}/{argument?}
     */
    private const COUNT_ROUTE_VARIABLES = 2;

    /**
     * Display header name.
     *
     * @var string
     */
    public $name;

    /**
     * Display header description.
     *
     * @var string
     */
    public $description;

    /**
     * Permission.
     *
     * @var string|array
     */
    public $permission;

    /**
     * @var Repository
     */
    private $source;

    /**
     * Button commands.
     *
     * @return Action[]
     */
    abstract public function commandBar(): array;

    /**
     * Views.
     *
     * @return Layout[]
     */
    abstract public function layout(): array;

    /**
     * @throws Throwable
     *
     * @return View
     */
    public function build()
    {
        return Layout::blank([
            $this->layout(),
        ])->build($this->source);
    }

    /**
     * @param string $method
     * @param string $slug
     *
     * @throws Throwable
     *
     * @return View
     */
    protected function asyncBuild(string $method, string $slug)
    {
        $query = $this->callMethod($method, request()->all());
        $source = new Repository($query);

        /** @var Base $layout */
        $layout = collect($this->layout())
            ->map(function ($layout) {
                return is_object($layout) ? $layout : app()->make($layout);
            })
            ->map(function (Base $layout) use ($slug) {
                return $layout->findBySlug($slug);
            })
            ->filter()
            ->whenEmpty(function () use ($slug) {
                abort(404, "Async template: {$slug} not found");
            })
            ->first();

        return $layout->currentAsync()->build($source);
    }

    /**
     * @param array $httpQueryArguments
     *
     * @throws ReflectionException
     *
     * @return Factory|\Illuminate\View\View
     */
    public function view(array $httpQueryArguments)
    {
        $query = $this->callMethod('query', $httpQueryArguments);

        $this->source = new Repository($query);
        $commandBar = $this->buildCommandBar($this->source);

        return view('platform::layouts.base', [
            'screen'     => $this,
            'commandBar' => $commandBar,
        ]);
    }

    /**
     * @param mixed ...$parameters
     *
     * @throws ReflectionException
     * @throws Throwable
     *
     * @return Factory|View|\Illuminate\View\View|mixed
     */
    public function handle(...$parameters)
    {
        abort_if(! $this->checkAccess(), 403);

        if (request()->isMethod('GET') || (! count($parameters))) {
            return $this->redirectOnGetMethodCallOrShowView($parameters);
        }

        $method = array_pop($parameters);

        if (Str::startsWith($method, 'async')) {
            return $this->asyncBuild($method, array_pop($parameters));
        }

        return $this->callMethod($method, $parameters);
    }

    /**
     * @param string $method
     * @param array  $httpQueryArguments
     *
     * @throws ReflectionException
     *
     * @return array
     */
    private function reflectionParams(string $method, array $httpQueryArguments = []): array
    {
        $class = new ReflectionClass($this);

        if (! is_string($method)) {
            return [];
        }

        if (! $class->hasMethod($method)) {
            return [];
        }

        $parameters = $class->getMethod($method)->getParameters();

        return collect($parameters)
            ->map(function ($parameter, $key) use ($httpQueryArguments) {
                return $this->bind($key, $parameter, $httpQueryArguments);
            })->all();
    }

    /**
     * It takes the serial number of the argument and the required parameter.
     * To convert to object.
     *
     * @param int                 $key
     * @param ReflectionParameter $parameter
     * @param array               $httpQueryArguments
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     *
     * @return mixed
     */
    private function bind(int $key, ReflectionParameter $parameter, array $httpQueryArguments)
    {
        $class = optional($parameter->getClass())->name;
        $original = array_values($httpQueryArguments)[$key] ?? null;

        if ($class === null) {
            return $original;
        }

        if (is_object($original)) {
            return $original;
        }

        $object = app()->make($class);

        if ($original !== null && is_a($object, UrlRoutable::class)) {
            return $object->resolveRouteBinding($original);
        }

        return $object;
    }

    /**
     * @return bool
     */
    private function checkAccess(): bool
    {
        return collect($this->permission)
            ->map(static function ($item) {
                return Auth::user()->hasAccess($item);
            })
            ->whenEmpty(function (Collection $permission) {
                return $permission->push(true);
            })
            ->contains(true);
    }

    /**
     * @param string $method
     * @param array  $parameters
     *
     * @throws ReflectionException
     *
     * @return mixed
     */
    private function callMethod(string $method, array $parameters = [])
    {
        return call_user_func_array([$this, $method],
            $this->reflectionParams($method, $parameters)
        );
    }

    /**
     * @return string
     */
    public function formValidateMessage(): string
    {
        return __('Please check the entered data, it may be necessary to specify in other languages.');
    }

    /**
     * Defines the URL to represent
     * the page based on the calculation of link arguments.
     *
     * @param array $httpQueryArguments
     *
     * @throws Throwable
     *
     * @return Factory|\Illuminate\Http\RedirectResponse|\Illuminate\View\View
     */
    protected function redirectOnGetMethodCallOrShowView(array $httpQueryArguments)
    {
        $expectedArg = count(request()->route()->getCompiled()->getVariables()) - self::COUNT_ROUTE_VARIABLES;
        $realArg = count($httpQueryArguments);

        if ($realArg <= $expectedArg) {
            return $this->view($httpQueryArguments);
        }

        array_pop($httpQueryArguments);

        return redirect()->action([static::class, 'handle'], $httpQueryArguments);
    }

    /**
     * Get can transfer to the screen only
     * user-created methods available in it.
     *
     * @array
     */
    public static function getAvailableMethods(): array
    {
        return array_diff(
            get_class_methods(static::class), // Custom methods
            get_class_methods(self::class),   // Basic methods
            ['query']                                   // Except methods
        );
    }
}
