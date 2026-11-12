<?php

namespace Dcat\Admin\Form\Field;

use Dcat\Admin\Form\Field;
use Dcat\Admin\Support\Helper;

/**
 * TinyMCE editor.
 *
 * @see https://www.tiny.cloud/docs
 * @see http://tinymce.ax-z.cn/
 */
class Editor extends Field
{
    protected $options = [
        'plugins' => [
            'advlist',
            'autolink',
            'link',
            'image',
            'media',
            'lists',
            'preview',
            'code',
            'help',
            'fullscreen',
            'table',
            'autoresize',
            'codesample',
        ],
        'toolbar' => [
            'undo redo | preview fullscreen | styles | fontsize bold italic underline strikethrough forecolor backcolor | link image media blockquote removeformat codesample',
            'alignleft aligncenter alignright  alignjustify| indent outdent bullist numlist table subscript superscript | code',
        ],
        'min_height' => 400,
        'save_enablewhendirty' => true,
        'convert_urls' => false,
        // TinyMCE 7+ 以 GPLv2+ 协议开源，自托管使用需声明 license_key；如已购买商业授权请改为授权码
        'license_key' => 'gpl',
        'promotion' => false,
    ];

    protected $disk;

    protected $imageUploadDirectory = 'tinymce/images';

    /**
     * 设置文件上传存储配置.
     *
     * @param  string  $disk
     * @return $this
     */
    public function disk(string $disk)
    {
        $this->disk = $disk;

        return $this;
    }

    /**
     * 设置图片上传文件夹.
     *
     * @param  string  $dir
     * @return $this
     */
    public function imageDirectory(string $dir)
    {
        $this->imageUploadDirectory = $dir;

        return $this;
    }

    /**
     * 自定义图片上传接口.
     *
     * @param  string  $url
     * @return $this
     */
    public function imageUrl(string $url)
    {
        return $this->mergeOptions(['images_upload_url' => $this->formatUrl(admin_url($url))]);
    }

    /**
     * 设置语言包url.
     *
     * @param  string  $url
     * @return $this
     */
    public function languageUrl(string $url)
    {
        return $this->mergeOptions(['language_url' => $url]);
    }

    /**
     * 设置编辑器高度.
     *
     * @param  int  $height
     * @return $this
     */
    public function height(int $height)
    {
        return $this->mergeOptions(['min_height' => $height]);
    }

    /**
     * @return array
     */
    protected function formatOptions()
    {
        $this->options = $this->normalizeLegacyOptions($this->options);

        if ($language = $this->resolveLanguage($this->options['language'] ?? config('app.locale'))) {
            $this->options['language'] = $language;
        } else {
            unset($this->options['language']);
        }
        $this->options['readonly'] = ! empty($this->attributes['readonly']) || ! empty($this->attributes['disabled']);

        if (empty($this->options['images_upload_url'])) {
            $this->options['images_upload_url'] = $this->defaultImageUploadUrl();
        }

        return $this->options;
    }

    /**
     * TinyMCE 6+ 语言包文件名使用连字符（zh-CN），且只有存在语言包时才设置 language，否则回退英文.
     *
     * @param  mixed  $locale
     * @return string|null
     */
    protected function resolveLanguage($locale)
    {
        if (! is_string($locale) || ! preg_match('/^[A-Za-z]{2,3}(?:[_-][A-Za-z0-9]{2,8})*$/', $locale)) {
            return null;
        }

        $locale = str_replace('_', '-', $locale);
        $candidates = [$locale, explode('-', $locale)[0]];

        foreach ($candidates as $candidate) {
            if (is_file(__DIR__.'/../../../resources/dist/dcat/plugins/tinymce/langs/'.$candidate.'.js')) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * 兼容 TinyMCE 5 的旧配置：工具栏按钮改名、已移除的插件自动剔除.
     *
     * @param  array  $options
     * @return array
     */
    protected function normalizeLegacyOptions(array $options)
    {
        $renamed = [
            'styleselect'    => 'styles',
            'fontsizeselect' => 'fontsize',
            'fontselect'     => 'fontfamily',
            'formatselect'   => 'blocks',
        ];
        $removedPlugins = [
            'bbcode', 'colorpicker', 'contextmenu', 'fullpage', 'hr', 'imagetools', 'legacyoutput', 'noneditable',
            'paste', 'print', 'spellchecker', 'tabfocus', 'template', 'textcolor', 'textpattern', 'toc',
        ];

        $rename = function ($toolbar) use ($renamed) {
            return is_string($toolbar)
                ? preg_replace_callback('/\b('.implode('|', array_keys($renamed)).')\b/', function ($m) use ($renamed) {
                    return $renamed[$m[1]];
                }, $toolbar)
                : $toolbar;
        };

        foreach ($options as $key => $value) {
            if (is_string($key) && preg_match('/^(toolbar\d*|quickbars_\w+_toolbar|contextmenu)$/', $key)) {
                $options[$key] = is_array($value) ? array_map($rename, $value) : $rename($value);
            }
        }

        if (isset($options['plugins'])) {
            $plugins = is_array($options['plugins'])
                ? $options['plugins']
                : preg_split('/[\s,]+/', (string) $options['plugins'], -1, PREG_SPLIT_NO_EMPTY);

            $options['plugins'] = array_values(array_diff(
                preg_split('/[\s,]+/', implode(' ', $plugins), -1, PREG_SPLIT_NO_EMPTY),
                $removedPlugins
            ));
        }

        return $options;
    }

    /**
     * @return string
     */
    protected function defaultImageUploadUrl()
    {
        return $this->formatUrl(route(admin_api_route_name('tinymce.upload')));
    }

    /**
     * @param  string  $url
     * @return string
     */
    protected function formatUrl(string $url)
    {
        return Helper::urlWithQuery(
            $url,
            [
                '_token' => csrf_token(),
                'disk'   => $this->disk,
                'dir'    => $this->imageUploadDirectory,
            ]
        );
    }

    /**
     * @return string
     */
    public function render()
    {
        $this->addVariables([
            'options' => $this->formatOptions(),
        ]);

        return parent::render();
    }
}
