<?php

namespace Dcat\Admin\Support;

use Dcat\Admin\Grid;
use Dcat\Laravel\Database\WhereHasInServiceProvider;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class Helper
{
    /**
     * @var array
     */
    public static $fileTypes = [
        'image'      => 'png|jpg|jpeg|tmp|gif',
        'word'       => 'doc|docx',
        'excel'      => 'xls|xlsx|csv',
        'powerpoint' => 'ppt|pptx',
        'pdf'        => 'pdf',
        'code'       => 'php|js|java|python|ruby|go|c|cpp|sql|m|h|json|html|aspx',
        'archive'    => 'zip|tar\.gz|rar|rpm',
        'txt'        => 'txt|pac|log|md',
        'audio'      => 'mp3|wav|flac|3pg|aa|aac|ape|au|m4a|mpc|ogg',
        'video'      => 'mkv|rmvb|flv|mp4|avi|wmv|rm|asf|mpeg',
    ];

    protected static $controllerNames = [];

    /**
     * 把给定的值转化为数组.
     *
     * @param $value
     * @param  bool  $filter
     * @return array
     */
    public static function array($value, bool $filter = true): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        if ($value instanceof \Closure) {
            $value = $value();
        }

        if (is_array($value)) {
        } elseif ($value instanceof Jsonable) {
            $value = json_decode($value->toJson(), true);
        } elseif ($value instanceof Arrayable) {
            $value = $value->toArray();
        } elseif (is_string($value)) {
            $array = null;

            try {
                $array = json_decode($value, true);
            } catch (\Throwable $e) {
            }

            $value = is_array($array) ? $array : explode(',', $value);
        } else {
            $value = (array) $value;
        }

        return $filter ? array_filter($value, function ($v) {
            return $v !== '' && $v !== null;
        }) : $value;
    }

    /**
     * 把给定的值转化为字符串.
     *
     * @param  string|Grid|\Closure|Renderable|Htmlable  $value
     * @param  array  $params
     * @param  object  $newThis
     * @return string
     */
    public static function render($value, $params = [], $newThis = null): string
    {
        if (is_string($value)) {
            return $value;
        }

        if ($value instanceof \Closure) {
            $newThis && ($value = $value->bindTo($newThis));

            $value = $value(...(array) $params);
        }

        if ($value instanceof Renderable) {
            return (string) $value->render();
        }

        if ($value instanceof Htmlable) {
            return (string) $value->toHtml();
        }

        return (string) $value;
    }

    /**
     * 获取当前控制器名称.
     *
     * @return mixed|string
     */
    public static function getControllerName()
    {
        $router = app('router');

        if (! $router->current()) {
            return 'undefined';
        }

        $actionName = $router->current()->getActionName();

        if (! isset(static::$controllerNames[$actionName])) {
            $controller = class_basename(explode('@', $actionName)[0]);

            static::$controllerNames[$actionName] = str_replace('Controller', '', $controller);
        }

        return static::$controllerNames[$actionName];
    }

    /**
     * @param  array  $attributes
     * @return string
     */
    public static function buildHtmlAttributes($attributes)
    {
        $html = '';

        foreach ((array) $attributes as $key => &$value) {
            if (is_array($value)) {
                $value = implode(' ', $value);
            }

            if (is_numeric($key)) {
                $key = $value;
            }

            $element = '';

            if ($value !== null) {
                $element = $key.'="'.htmlentities($value, ENT_QUOTES, 'UTF-8').'" ';
            }

            $html .= $element;
        }

        return $html;
    }

    /**
     * @param  string  $url
     * @param  array  $query
     * @return string
     */
    public static function urlWithQuery(?string $url, array $query = [])
    {
        if (! $url || ! $query) {
            return $url;
        }

        $array = explode('?', $url);

        $url = $array[0];

        parse_str($array[1] ?? '', $originalQuery);

        return $url.'?'.http_build_query(array_merge($originalQuery, $query));
    }

    /**
     * @param  string  $url
     * @param  string|array|Arrayable  $keys
     * @return string
     */
    public static function urlWithoutQuery($url, $keys)
    {
        if (! Str::contains($url, '?') || ! $keys) {
            return $url;
        }

        if ($keys instanceof Arrayable) {
            $keys = $keys->toArray();
        }

        $keys = (array) $keys;

        $urlInfo = parse_url($url);

        parse_str($urlInfo['query'], $query);

        Arr::forget($query, $keys);

        $baseUrl = explode('?', $url)[0];

        return $query
            ? $baseUrl.'?'.http_build_query($query)
            : $baseUrl;
    }

    /**
     * @param  Arrayable|array|string  $keys
     * @return string
     */
    public static function fullUrlWithoutQuery($keys)
    {
        return static::urlWithoutQuery(request()->fullUrl(), $keys);
    }

    /**
     * @param  string  $url
     * @param  string|array  $keys
     * @return bool
     */
    public static function urlHasQuery(string $url, $keys)
    {
        $value = explode('?', $url);

        if (empty($value[1])) {
            return false;
        }

        parse_str($value[1], $query);

        foreach ((array) $keys as $key) {
            if (Arr::has($query, $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 匹配请求路径.
     *
     * @example
     *      Helper::matchRequestPath(admin_base_path('auth/user'))
     *      Helper::matchRequestPath(admin_base_path('auth/user*'))
     *      Helper::matchRequestPath(admin_base_path('auth/user/* /edit'))
     *      Helper::matchRequestPath('GET,POST:auth/user')
     *
     * @param  string  $path
     * @param  null|string  $current
     * @return bool
     */
    public static function matchRequestPath($path, ?string $current = null)
    {
        $request = request();
        $current = $current ?: $request->decodedPath();

        if (Str::contains($path, ':')) {
            [$methods, $path] = explode(':', $path);

            $methods = array_map('strtoupper', explode(',', $methods));

            if (! empty($methods) && ! in_array($request->method(), $methods)) {
                return false;
            }
        }

        // 判断路由名称
        if ($request->routeIs($path) || $request->routeIs(admin_route_name($path))) {
            return true;
        }

        if (! Str::contains($path, '*')) {
            return $path === $current;
        }

        // 转义正则特殊字符并锚定开头：原先未锚定的正则只要“包含”即匹配，
        // 例如权限 "users*" 会放行 "auth/users/1/edit"，导致越权
        $pattern = str_replace('\*', '([0-9a-z-_,])*', preg_quote(ltrim($path, '/'), '/'));

        return (bool) preg_match("/^{$pattern}/i", ltrim((string) $current, '/'));
    }

    /**
     * 生成层级数据.
     *
     * @param  array  $nodes
     * @param  int  $parentId
     * @param  string|null  $primaryKeyName
     * @param  string|null  $parentKeyName
     * @param  string|null  $childrenKeyName
     * @return array
     */
    public static function buildNestedArray(
        $nodes = [],
        $parentId = 0,
        ?string $primaryKeyName = null,
        ?string $parentKeyName = null,
        ?string $childrenKeyName = null
    ) {
        $branch = [];
        $primaryKeyName = $primaryKeyName ?: 'id';
        $parentKeyName = $parentKeyName ?: 'parent_id';
        $childrenKeyName = $childrenKeyName ?: 'children';

        $parentId = is_numeric($parentId) ? (int) $parentId : $parentId;

        foreach ($nodes as $node) {
            $pk = $node[$parentKeyName];
            $pk = is_numeric($pk) ? (int) $pk : $pk;

            if ($pk === $parentId) {
                $children = static::buildNestedArray(
                    $nodes,
                    $node[$primaryKeyName],
                    $primaryKeyName,
                    $parentKeyName,
                    $childrenKeyName
                );

                if ($children) {
                    $node[$childrenKeyName] = $children;
                }
                $branch[] = $node;
            }
        }

        return $branch;
    }

    /**
     * @param  string  $name
     * @param  string  $symbol
     * @return mixed
     */
    public static function slug(string $name, string $symbol = '-')
    {
        $text = preg_replace_callback('/([A-Z])/', function ($text) use ($symbol) {
            return $symbol.strtolower($text[1]);
        }, $name);

        return str_replace('_', $symbol, ltrim($text, $symbol));
    }

    /**
     * @param  array  $array
     * @param  int  $level
     * @return string
     */
    public static function exportArray(array &$array, $level = 1)
    {
        $start = '[';
        $end = ']';

        $txt = "$start\n";

        foreach ($array as $k => &$v) {
            if (is_array($v)) {
                $pre = is_string($k) ? "'$k' => " : "$k => ";

                $txt .= str_repeat(' ', $level * 4).$pre.static::exportArray($v, $level + 1).",\n";

                continue;
            }
            $t = $v;

            if ($v === true) {
                $t = 'true';
            } elseif ($v === false) {
                $t = 'false';
            } elseif ($v === null) {
                $t = 'null';
            } elseif (is_string($v)) {
                $v = str_replace("'", "\\'", $v);
                $t = "'$v'";
            }

            $pre = is_string($k) ? "'$k' => " : "$k => ";

            $txt .= str_repeat(' ', $level * 4)."{$pre}{$t},\n";
        }

        return $txt.str_repeat(' ', ($level - 1) * 4).$end;
    }

    /**
     * @param  array  $array
     * @return string
     */
    public static function exportArrayPhp(array $array)
    {
        return "<?php \nreturn ".static::exportArray($array).";\n";
    }

    /**
     * 删除数组中的元素.
     *
     * @param  array  $array
     * @param  mixed  $value
     * @param  bool  $strict
     */
    public static function deleteByValue(&$array, $value, bool $strict = false)
    {
        $value = (array) $value;

        foreach ($array as $index => $item) {
            if (in_array($item, $value, $strict)) {
                unset($array[$index]);
            }
        }
    }

    /**
     * @param  array  $array
     * @param  mixed  $value
     */
    public static function deleteContains(&$array, $value)
    {
        $value = (array) $value;

        foreach ($array as $index => $item) {
            foreach ($value as  $v) {
                if (Str::contains($item, $v)) {
                    unset($array[$index]);
                }
            }
        }
    }

    /**
     * 颜色转亮.
     *
     * @param  string  $color
     * @param  int  $amt
     * @return string
     */
    public static function colorLighten(string $color, int $amt)
    {
        if (! $amt) {
            return $color;
        }

        $hasPrefix = false;

        if (mb_strpos($color, '#') === 0) {
            $color = mb_substr($color, 1);

            $hasPrefix = true;
        }

        [$red, $blue, $green] = static::colorToRBG($color, $amt);

        return ($hasPrefix ? '#' : '').dechex($green + ($blue << 8) + ($red << 16));
    }

    /**
     * 颜色转暗.
     *
     * @param  string  $color
     * @param  int  $amt
     * @return string
     */
    public static function colorDarken(string $color, int $amt)
    {
        return static::colorLighten($color, -$amt);
    }

    /**
     * 颜色透明度.
     *
     * @param  string  $color
     * @param  float|string  $alpha
     * @return string
     */
    public static function colorAlpha(string $color, $alpha)
    {
        if ($alpha >= 1) {
            return $color;
        }

        if (mb_strpos($color, '#') === 0) {
            $color = mb_substr($color, 1);
        }

        [$red, $blue, $green] = static::colorToRBG($color);

        return "rgba($red, $blue, $green, $alpha)";
    }

    /**
     * @param  string  $color
     * @param  int  $amt
     * @return array
     */
    public static function colorToRBG(string $color, int $amt = 0)
    {
        $format = function ($value) {
            if ($value > 255) {
                return 255;
            }
            if ($value < 0) {
                return 0;
            }

            return $value;
        };

        $num = hexdec($color);

        $red = $format(($num >> 16) + $amt);
        $blue = $format((($num >> 8) & 0x00FF) + $amt);
        $green = $format(($num & 0x0000FF) + $amt);

        return [$red, $blue, $green];
    }

    /**
     * 验证扩展包名称.
     *
     * @param  string  $name
     * @return int
     */
    public static function validateExtensionName($name)
    {
        return preg_match('/^[\w\-_]+\/[\w\-_]+$/', $name);
    }

    /**
     * Get file icon.
     *
     * @param  string  $file
     * @return string
     */
    public static function getFileIcon($file = '')
    {
        $extension = File::extension($file);

        foreach (static::$fileTypes as $type => $regex) {
            if (preg_match("/^($regex)$/i", $extension) !== 0) {
                return "fa fa-file-{$type}-o";
            }
        }

        return 'fa fa-file-o';
    }

    /**
     * 判断是否是ajax请求.
     *
     * @param  Request  $request
     * @return bool
     */
    public static function isAjaxRequest(?Request $request = null)
    {
        /* @var Request $request */
        $request = $request ?: request();

        return $request->ajax() && ! $request->pjax();
    }

    /**
     * 判断是否是IE浏览器.
     *
     * @return false|int
     */
    public static function isIEBrowser()
    {
        return (bool) preg_match('/Mozilla\/5\.0 \(Windows NT 10\.0; WOW64; Trident\/7\.0; rv:[0-9\.]*\) like Gecko/i', $_SERVER['HTTP_USER_AGENT'] ?? '');
    }

    /**
     * 判断是否QQ浏览器.
     *
     * @return bool
     */
    public static function isQQBrowser()
    {
        return mb_strpos(mb_strtolower($_SERVER['HTTP_USER_AGENT'] ?? ''), 'qqbrowser') !== false;
    }

    /**
     * @param  string  $url
     * @return void
     */
    public static function setPreviousUrl($url)
    {
        session()->flash('admin.prev.url', static::urlWithoutQuery((string) $url, '_pjax'));
    }

    /**
     * @return string
     */
    public static function getPreviousUrl()
    {
        return (string) (session()->get('admin.prev.url') ? url(session()->get('admin.prev.url')) : url()->previous());
    }

    /**
     * @param  mixed  $command
     * @param  int  $timeout
     * @param  null  $input
     * @param  null  $cwd
     * @return Process
     */
    public static function process($command, $timeout = 100, $input = null, $cwd = null)
    {
        $parameters = [
            $command,
            $cwd,
            [],
            $input,
            $timeout,
        ];

        return is_string($command)
            ? Process::fromShellCommandline(...$parameters)
            : new Process(...$parameters);
    }

    /**
     * 判断两个值是否相等.
     *
     * @param $value1
     * @param $value2
     * @return bool
     */
    public static function equal($value1, $value2)
    {
        if ($value1 === null || $value2 === null) {
            return false;
        }

        if (! is_scalar($value1) || ! is_scalar($value2)) {
            return $value1 === $value2;
        }

        return (string) $value1 === (string) $value2;
    }

    /**
     * 判断给定的数组是是否包含给定元素.
     *
     * @param  mixed  $value
     * @param  array  $array
     * @return bool
     */
    public static function inArray($value, array $array)
    {
        $array = array_map(function ($v) {
            if (is_scalar($v) || $v === null) {
                $v = (string) $v;
            }

            return $v;
        }, $array);

        return in_array((string) $value, $array, true);
    }

    /**
     * Limit the number of characters in a string.
     *
     * @param  string  $value
     * @param  int  $limit
     * @param  string  $end
     * @return string
     */
    public static function strLimit($value, $limit = 100, $end = '...')
    {
        if (mb_strlen($value, 'UTF-8') <= $limit) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, $limit, 'UTF-8')).$end;
    }

    /**
     * 获取类名或对象的文件路径.
     *
     * @param  string|object  $class
     * @return string
     *
     * @throws \ReflectionException
     */
    public static function guessClassFileName($class)
    {
        if (is_object($class)) {
            $class = get_class($class);
        }

        try {
            if (class_exists($class)) {
                return (new \ReflectionClass($class))->getFileName();
            }
        } catch (\Throwable $e) {
        }

        $class = trim($class, '\\');

        $composer = Composer::parse(base_path('composer.json'));

        $map = collect($composer->autoload['psr-4'] ?? [])->mapWithKeys(function ($path, $namespace) {
            $namespace = trim($namespace, '\\').'\\';

            return [$namespace => [$namespace, $path]];
        })->sortBy(function ($_, $namespace) {
            return strlen($namespace);
        }, SORT_REGULAR, true);

        $prefix = explode($class, '\\')[0];

        if ($map->isEmpty()) {
            if (Str::startsWith($class, 'App\\')) {
                $values = ['App\\', 'app/'];
            }
        } else {
            $values = $map->filter(function ($_, $k) use ($class) {
                return Str::startsWith($class, $k);
            })->first();
        }

        if (empty($values)) {
            $values = [$prefix.'\\', self::slug($prefix).'/'];
        }

        [$namespace, $path] = $values;

        return base_path(str_replace([$namespace, '\\'], [$path, '/'], $class)).'.php';
    }

    /**
     * Is input data is has-one relation.
     *
     * @param  Collection  $fields
     * @param  array  $input
     */
    public static function prepareHasOneRelation(Collection $fields, array &$input)
    {
        $relations = [];
        $fields->each(function ($field) use (&$relations) {
            $column = $field->column();

            if (is_array($column)) {
                foreach ($column as $v) {
                    if (Str::contains($v, '.')) {
                        $first = explode('.', $v)[0];
                        $relations[$first] = null;
                    }
                }

                return;
            }

            if (Str::contains($column, '.')) {
                $first = explode('.', $column)[0];
                $relations[$first] = null;
            }
        });

        foreach ($relations as $first => $v) {
            if (isset($input[$first])) {
                foreach ($input[$first] as $key => $value) {
                    if (is_array($value)) {
                        $input["$first.$key"] = $value;
                    }
                }

                $input = array_merge($input, Arr::dot([$first => $input[$first]]));
            }
        }
    }

    /**
     * 设置查询条件.
     *
     * @param  mixed  $model
     * @param  string  $column
     * @param  string  $query
     * @param mixed array $params
     * @return void
     */
    public static function withQueryCondition($model, ?string $column, string $query, array $params)
    {
        if (! Str::contains($column, '.')) {
            $model->$query($column, ...$params);

            return;
        }

        $method = $query === 'orWhere' ? 'orWhere' : 'where';
        $subQuery = $query === 'orWhere' ? 'where' : $query;

        $model->$method(function ($q) use ($column, $subQuery, $params) {
            static::withRelationQuery($q, $column, $subQuery, $params);
        });
    }

    /**
     * 设置关联关系查询条件.
     *
     * @param  mixed  $model
     * @param  string  $column
     * @param  string  $query
     * @param  mixed  ...$params
     * @return void
     */
    public static function withRelationQuery($model, ?string $column, string $query, array $params)
    {
        $column = explode('.', $column);

        $relColumn = array_pop($column);

        // 增加对whereHasIn的支持
        $method = class_exists(WhereHasInServiceProvider::class) ? 'whereHasIn' : 'whereHas';

        $model->$method(implode('.', $column), function ($relation) use ($relColumn, $params, $query) {
            $table = $relation->getModel()->getTable();
            $relation->$query("{$table}.{$relColumn}", ...$params);
        });
    }

    /**
     * Html转义.
     *
     * @param  array|string  $item
     * @return mixed
     */
    public static function htmlEntityEncode($item)
    {
        if (is_object($item)) {
            return $item;
        }
        if (is_array($item)) {
            array_walk_recursive($item, function (&$value) {
                $value = htmlentities($value ?? '');
            });
        } else {
            $item = htmlentities($item ?? '');
        }

        return $item;
    }

    /**
     * 格式化表单元素 name 属性.
     *
     * @param  string|array  $name
     * @return mixed|string
     */
    public static function formatElementName($name)
    {
        if (! $name) {
            return $name;
        }

        if (is_array($name)) {
            foreach ($name as &$v) {
                $v = static::formatElementName($v);
            }

            return $name;
        }

        $name = explode('.', $name);

        if (count($name) == 1) {
            return $name[0];
        }

        $html = array_shift($name);
        foreach ($name as $piece) {
            $html .= "[$piece]";
        }

        return $html;
    }

    /**
     * Set an array item to a given value using "dot" notation.
     *
     * If no key is given to the method, the entire array will be replaced.
     *
     * @param  array|\ArrayAccess  $array
     * @param  string  $key
     * @param  mixed  $value
     * @return array
     */
    public static function arraySet(&$array, $key, $value)
    {
        if (is_null($key)) {
            return $array = $value;
        }

        $keys = explode('.', $key);
        $default = null;

        while (count($keys) > 1) {
            $key = array_shift($keys);

            if (! isset($array[$key]) || (! is_array($array[$key]) && ! $array[$key] instanceof \ArrayAccess)) {
                $array[$key] = [];
            }

            if (is_array($array)) {
                $array = &$array[$key];
            } else {
                if (is_object($array[$key])) {
                    $array[$key] = static::arraySet($array[$key], implode('.', $keys), $value);
                } else {
                    $mid = $array[$key];

                    $array[$key] = static::arraySet($mid, implode('.', $keys), $value);
                }
            }
        }

        $array[array_shift($keys)] = $value;

        return $array;
    }

    /**
     * 把下划线风格字段名转化为驼峰风格.
     *
     * @param  array  $array
     * @return array
     */
    public static function camelArray(array &$array)
    {
        foreach ($array as $k => $v) {
            if (is_array($v)) {
                Helper::camelArray($v);
            }

            $array[Str::camel($k)] = $v;
        }

        return $array;
    }

    /**
     * 获取文件名称.
     *
     * @param  string  $name
     * @return array|mixed
     */
    public static function basename($name)
    {
        if (! $name) {
            return $name;
        }

        return last(explode('/', $name));
    }

    /**
     * @param  string|int  $key
     * @param  array|object  $arrayOrObject
     * @return bool
     */
    public static function keyExists($key, $arrayOrObject)
    {
        if (is_object($arrayOrObject)) {
            $arrayOrObject = static::array($arrayOrObject, false);
        }

        return array_key_exists($key, $arrayOrObject);
    }

    /**
     * 跳转.
     *
     * @param  string  $to
     * @param  int  $statusCode
     * @param  Request  $request
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory|\Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse|\Illuminate\Http\Response|\Illuminate\Routing\Redirector
     */
    public static function redirect($to, int $statusCode = 302, $request = null)
    {
        $request = $request ?: request();

        if (! URL::isValidUrl($to)) {
            $to = admin_base_path($to);
        }

        if ($request->ajax() && ! $request->pjax()) {
            return response()->json(['redirect' => $to], $statusCode);
        }

        if ($request->pjax()) {
            return response('<script>location.href = '.static::jsString($to).';</script>');
        }

        $redirectCodes = [201, 301, 302, 303, 307, 308];

        return redirect($to, in_array($statusCode, $redirectCodes, true) ? $statusCode : 302);
    }
        
    /**
     * 把图片压缩成webp
     *
     * @param [type] 输入图片地址
     * @param [type] 输出图片地址
     * @return bool
     */
    public static function convertToWebP($inputFile, $outputFile)
    {
        try {
            if (! class_exists(\Imagick::class) || ! is_file($inputFile)) {
                return false;
            }
            // 创建 Imagick 对象
            $imagick = new \Imagick($inputFile);

            // 检查是否是 GIF 格式
            if ($imagick->getImageFormat() === 'GIF') {
                // 如果是 GIF，保留帧并生成动态 WebP
                $imagick = $imagick->coalesceImages(); // 解开所有帧
                $imagick->stripImage(); //去除元数据
                foreach ($imagick as $frame) {
                    $frame->setImageFormat('WEBP'); // 设置帧格式为 WebP
                    $frame->setOption('webp:lossless', 'true'); // 可选：无损压缩
                    $frame->setImageDelay($frame->getImageDelay()); // 保留原始延迟时间
                }
                $imagick = $imagick->deconstructImages(); // 压缩所有帧
                $imagick->writeImages($outputFile, true); // 保存为动态 WebP
            } else {
                // 如果不是 GIF，则直接转换为静态 WebP
                $imagick->setImageFormat('WEBP');
                $imagick->setOption('webp:lossless', 'false'); // 可选：有损压缩
                $imagick->writeImage($outputFile);
            }

            return true;
            // echo "Conversion successful: {$outputFile}\n";

        } catch (\Throwable $e) {
            // 不要 echo 异常信息：既会泄露服务器路径，也会破坏 JSON 响应
            if (function_exists('report')) {
                report($e);
            }

            return false;
        }
    }

    /**
     * 将字符串编码为可安全嵌入 <script> 的 JS 字符串字面量.
     *
     * @param  string  $value
     * @return string
     */
    public static function jsString($value)
    {
        return json_encode(
            (string) $value,
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    /**
     * 默认禁止上传的文件后缀（可执行脚本 / 服务器配置 / 可被浏览器执行的文档）.
     * 可通过配置 admin.upload.forbidden_extensions 覆盖.
     *
     * @var array
     */
    public static $forbiddenUploadExtensions = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'pht', 'phtml', 'phar', 'phps', 'pgif', 'inc',
        'shtml', 'shtm', 'stm', 'htaccess', 'htpasswd', 'ini', 'user.ini',
        'cgi', 'pl', 'py', 'pyc', 'rb', 'sh', 'bash', 'asp', 'aspx', 'asa', 'asax', 'ascx', 'ashx', 'asmx', 'cer',
        'jsp', 'jspx', 'jsw', 'jsv', 'jspf', 'war', 'exe', 'com', 'bat', 'cmd', 'dll', 'msi', 'vbs', 'ps1',
        'html', 'htm', 'xhtml', 'xht', 'svg', 'svgz', 'xml', 'xsl', 'swf', 'hta',
    ];

    /**
     * 编辑器图片上传允许的后缀.
     *
     * @var array
     */
    public static $imageUploadExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

    /**
     * 判断上传文件后缀是否被禁止.
     *
     * @param  string|null  $filename  文件名或后缀
     * @return bool
     */
    public static function isForbiddenUploadExtension($filename)
    {
        $filename = strtolower(trim((string) $filename));

        if ($filename === '') {
            return false;
        }

        $forbidden = array_map('strtolower', (array) config('admin.upload.forbidden_extensions', static::$forbiddenUploadExtensions));

        // 去掉结尾的点和空白，防止 shell.php. / "shell.php " 绕过（Windows 会自动去掉）
        $parts = explode('.', rtrim($filename, ". \t\n\r\0\x0B"));
        $last = end($parts);

        if (in_array($last, $forbidden, true)) {
            return true;
        }

        // .user.ini / .htaccess 等
        if (count($parts) === 3 && $parts[0] === '' && in_array($parts[1].'.'.$parts[2], $forbidden, true)) {
            return true;
        }

        // 中间段为服务端脚本后缀时同样拒绝，防止 Apache 多后缀解析：shell.php.xxx
        array_shift($parts);
        array_pop($parts);

        foreach ($parts as $part) {
            if (preg_match('/^(php\d?|pht|phtml|phar|phps|shtml?|cgi|pl|py|asp|aspx|jsp|jspx)$/', $part)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 校验上传文件是否为真实图片（后缀白名单 + 文件内容 MIME 检测）.
     *
     * @param  \Symfony\Component\HttpFoundation\File\UploadedFile|null  $file
     * @return bool
     */
    public static function isSafeImageUpload($file)
    {
        if (! $file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile || ! $file->isValid()) {
            return false;
        }

        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, static::$imageUploadExtensions, true)) {
            return false;
        }

        if (static::isForbiddenUploadExtension($file->getClientOriginalName())) {
            return false;
        }

        // getMimeType() 基于文件内容（finfo）检测，而不是客户端提交的 Content-Type
        $mime = (string) $file->getMimeType();

        return Str::startsWith($mime, 'image/') && $mime !== 'image/svg+xml';
    }

    /**
     * 过滤上传目录，防止目录穿越.
     *
     * @param  string|null  $directory
     * @return string
     *
     * @throws \InvalidArgumentException
     */
    public static function sanitizeUploadDirectory($directory)
    {
        $directory = trim(str_replace('\\', '/', (string) $directory), '/');

        if ($directory === '') {
            return '';
        }

        foreach (explode('/', $directory) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || ! preg_match('/^[\w\-.]+$/u', $segment)) {
                throw new \InvalidArgumentException('Invalid upload directory.');
            }
        }

        return $directory;
    }

    /**
     * 判断跳转地址是否安全：仅允许站内相对路径，或与当前站点 / app.url / admin 域名同主机的 http(s) 地址.
     * 用于防止开放重定向以及 javascript: 伪协议 XSS.
     *
     * @param  mixed  $url
     * @return bool
     */
    public static function isSafeRedirectUrl($url)
    {
        if (! is_string($url) || ($url = trim($url)) === '') {
            return false;
        }

        // 控制字符、协议相对地址（//evil.com、/\evil.com）
        if (preg_match('/[\x00-\x1F\x7F]/', $url) || preg_match('#^[/\\\\]{2}#', $url) || preg_match('#^/\\\\#', $url)) {
            return false;
        }

        $parts = parse_url($url);

        if ($parts === false) {
            return false;
        }

        if (empty($parts['scheme']) && empty($parts['host'])) {
            // 相对路径，但不能是 "javascript:xxx" 这类没有被解析出 scheme 的写法
            return ! preg_match('/^[a-z][a-z0-9+.\-]*:/i', $url);
        }

        if (empty($parts['host']) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return false;
        }

        $hosts = array_filter(array_map('strtolower', array_merge(
            [request()->getHost(), (string) parse_url((string) config('app.url'), PHP_URL_HOST), (string) config('admin.route.domain')],
            (array) config('admin.redirect_hosts', [])
        )));

        return in_array(strtolower($parts['host']), $hosts, true);
    }

    /**
     * 判断磁盘内的相对路径是否安全（不含目录穿越、空字节等）.
     *
     * @param  string|null  $path
     * @return bool
     */
    public static function isSafeStoragePath($path)
    {
        $path = str_replace('\\', '/', (string) $path);

        if ($path === '' || strpos($path, "\0") !== false || preg_match('#^[a-z][a-z0-9+.\-]*:#i', $path)) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                return false;
            }
        }

        return true;
    }
}
