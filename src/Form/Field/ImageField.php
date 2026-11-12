<?php

namespace Dcat\Admin\Form\Field;

use Dcat\Admin\Exception\AdminException;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\File\UploadedFile;

trait ImageField
{
    /**
     * Intervention calls.
     *
     * @var array
     */
    protected $interventionCalls = [];

    /**
     * Thumbnail settings.
     *
     * @var array
     */
    protected $thumbnails = [];

    protected static $interventionAlias = [
        'filling' => 'fill',
    ];

    /**
     * Default directory for file to upload.
     *
     * @return mixed
     */
    public function defaultDirectory()
    {
        return config('admin.upload.directory.image');
    }

    /**
     * Execute Intervention calls.
     *
     * @param  string  $target
     * @param  string  $mime
     * @return mixed
     */
    public function callInterventionMethods($target, $mime)
    {
        if (! empty($this->interventionCalls)) {
            $image = $this->makeInterventionImage($target);

            $mime = $mime ?: (new \finfo(FILEINFO_MIME_TYPE))->file($target);

            foreach ($this->interventionCalls as $call) {
                if (static::interventionVersion() >= 3) {
                    [$method, $arguments] = $this->convertInterventionCall($call['method'], $call['arguments']);

                    $image = call_user_func_array([$image, $method], $arguments) ?: $image;

                    // 临时文件通常没有后缀，按 MIME 编码后写回
                    $image->encodeByMediaType($mime)->save($target);
                } else {
                    call_user_func_array(
                        [$image, $call['method']],
                        $call['arguments']
                    )->save($target, null, $mime);
                }
            }
        }

        return $target;
    }

    /**
     * 当前安装的 intervention/image 主版本：0 未安装，2 = v2，3 = v3+.
     *
     * @return int
     */
    protected static function interventionVersion()
    {
        if (class_exists(\Intervention\Image\ImageManager::class) && method_exists(\Intervention\Image\ImageManager::class, 'read')) {
            return 3;
        }

        return class_exists(\Intervention\Image\ImageManagerStatic::class) ? 2 : 0;
    }

    /**
     * 兼容 intervention/image v2 与 v3 创建图片实例.
     *
     * @param  string|UploadedFile  $source
     * @return mixed
     */
    protected function makeInterventionImage($source)
    {
        $path = $source instanceof \SplFileInfo ? $source->getRealPath() : $source;

        if (static::interventionVersion() >= 3) {
            $driver = config('admin.upload.image_driver') ?: (extension_loaded('gd') ? 'gd' : 'imagick');

            $manager = $driver === 'imagick'
                ? \Intervention\Image\ImageManager::imagick()
                : \Intervention\Image\ImageManager::gd();

            return $manager->read($path);
        }

        return \Intervention\Image\ImageManagerStatic::make($path);
    }

    /**
     * 把 intervention/image v2 的方法调用转换为 v3 的等价调用.
     *
     * @param  string  $method
     * @param  array  $arguments
     * @return array
     */
    protected function convertInterventionCall($method, array $arguments)
    {
        // v2 的约束回调（aspectRatio / upsize）在 v3 中已移除
        $hasConstraint = false;
        $arguments = array_values(array_filter($arguments, function ($argument) use (&$hasConstraint) {
            if ($argument instanceof \Closure) {
                $hasConstraint = true;

                return false;
            }

            return true;
        }));

        switch ($method) {
            case 'resize':
                // 带约束回调的 resize 在 v2 中基本都是等比缩放
                return [$hasConstraint ? 'scale' : 'resize', $arguments];
            case 'fit':
                return ['cover', [$arguments[0] ?? null, $arguments[1] ?? ($arguments[0] ?? null)]];
            case 'widen':
                return ['scale', [$arguments[0] ?? null, null]];
            case 'heighten':
                return ['scale', [null, $arguments[0] ?? null]];
            case 'insert':
                return ['place', $arguments];
            default:
                return [$method, $arguments];
        }
    }

    /**
     * Call intervention methods.
     *
     * @param  string  $method
     * @param  array  $arguments
     * @return $this
     *
     * @throws \Exception
     */
    public function __call($method, $arguments)
    {
        if (static::hasMacro($method)) {
            return parent::__call($method, $arguments);
        }

        if (! static::interventionVersion()) {
            throw new AdminException('To use image handling and manipulation, please install [intervention/image] first.');
        }

        $this->interventionCalls[] = [
            'method'    => static::$interventionAlias[$method] ?? $method,
            'arguments' => $arguments,
        ];

        return $this;
    }

    /**
     * @param  string|array  $name
     * @param  int  $width
     * @param  int  $height
     * @return $this
     */
    public function thumbnail($name, ?int $width = null, ?int $height = null)
    {
        if (func_num_args() == 1 && is_array($name)) {
            foreach ($name as $key => $size) {
                if (count($size) >= 2) {
                    $this->thumbnails[$key] = $size;
                }
            }
        } elseif (func_num_args() == 3) {
            $this->thumbnails[$name] = [$width, $height];
        }

        return $this;
    }

    /**
     * Destroy original thumbnail files.
     *
     * @param  string|array  $file
     * @param  bool  $force
     * @return void.
     */
    public function destroyThumbnail($file = null, bool $force = false)
    {
        if ($this->retainable && ! $force) {
            return;
        }

        $file = $file ?: $this->original;
        if (! $file) {
            return;
        }

        if (is_array($file)) {
            foreach ($file as $f) {
                $this->destroyThumbnail($f, $force);
            }

            return;
        }

        foreach ($this->thumbnails as $name => $_) {
            // We need to get extension type ( .jpeg , .png ...)
            $ext = pathinfo($file, PATHINFO_EXTENSION);

            // We remove extension from file name so we can append thumbnail type
            $path = Str::replaceLast('.'.$ext, '', $file);

            // We merge original name + thumbnail name + extension
            $path = $path.'-'.$name.'.'.$ext;

            if ($this->getStorage()->exists($path)) {
                $this->getStorage()->delete($path);
            }
        }
    }

    /**
     * Upload file and delete original thumbnail files.
     *
     * @param  UploadedFile  $file
     * @return $this
     */
    protected function uploadAndDeleteOriginalThumbnail(UploadedFile $file)
    {
        foreach ($this->thumbnails as $name => $size) {
            // We need to get extension type ( .jpeg , .png ...)
            $ext = pathinfo($this->name, PATHINFO_EXTENSION);

            // We remove extension from file name so we can append thumbnail type
            $path = Str::replaceLast('.'.$ext, '', $this->name);

            // We merge original name + thumbnail name + extension
            $path = $path.'-'.$name.'.'.$ext;

            if (! static::interventionVersion()) {
                throw new AdminException('To use image handling and manipulation, please install [intervention/image] first.');
            }

            $image = $this->makeInterventionImage($file);

            $action = $size[2] ?? 'resize';

            if (static::interventionVersion() >= 3) {
                // Resize image with aspect ratio
                [$method, $arguments] = $this->convertInterventionCall($action, [$size[0], $size[1], function () {
                }]);

                $image = call_user_func_array([$image, $method], $arguments) ?: $image;

                $contents = $image->encodeByExtension(strtolower($ext) ?: 'jpg')->toString();
            } else {
                // Resize image with aspect ratio
                $image->$action($size[0], $size[1], function ($constraint) {
                    $constraint->aspectRatio();
                });

                $contents = $image->encode()->stream();
            }

            if (! is_null($this->storagePermission)) {
                $this->getStorage()->put("{$this->getDirectory()}/{$path}", $contents, $this->storagePermission);
            } else {
                $this->getStorage()->put("{$this->getDirectory()}/{$path}", $contents);
            }
        }

        if (! is_array($this->original)) {
            $this->destroyThumbnail();
        }

        return $this;
    }
}
