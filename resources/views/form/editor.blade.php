<div class="{{$viewClass['form-group']}}">

    <label class="{{$viewClass['label']}} control-label">{!! $label !!}</label>

    <div class="{{$viewClass['field']}}">

        @include('admin::form.error')

        <textarea class="form-control {{$class}}" name="{{$name}}" placeholder="{{ $placeholder }}" {!! $attributes !!} >{{ $value }}</textarea>

        @include('admin::form.help-block')

    </div>
</div>

<script require="@tinymce" init="{!! $selector !!}">
    var opts = {!! admin_javascript_json($options) !!};
    var fileSize = {{env('UPLOAD_IMAGE_SIZE', 2 * 1024 * 1024)}}; //文件（图片）大小；
    var alertContent = '文件大小不能超过 ' + fileSize/1024/1024 + ' MB';

    opts.selector = '#'+id;
    
    opts.setup = function (editor) {
        // 删除、替换或撤销图片只改变待保存正文；物理文件由业务系统核查引用后清理。
        //监听粘贴操作  限制粘贴图片大小
        editor.on('paste', function (e) {
            var clipboardData = e.clipboardData || window.clipboardData;
            var items = clipboardData.items;
            for (var i = 0; i < items.length; i++) {
                var item = items[i];
                // 检查是否为文件类型
                if (item.kind === 'file') {
                    var file = item.getAsFile();
                    // 检查文件大小
                    if (file.size > fileSize) { // 设置最大文件大小为 2MB
                        e.preventDefault(); // 阻止默认粘贴行为
                        alert(alertContent);//提示
                        return;
                    }
                }
            }
        });

        // 保留图片替换后的业务回调，旧图片文件仍交由引用核查流程处理。
        let selectedImageSrc = null;
        editor.on('click', function (e) {
            selectedImageSrc = e.target.nodeName === 'IMG' ? e.target.src : null;
        });

        const observer = new MutationObserver(function (mutationsList) {
            for (const mutation of mutationsList) {
                if (mutation.type !== 'attributes' || mutation.target.nodeName !== 'IMG' ||
                    mutation.attributeName !== 'src' || !selectedImageSrc ||
                    mutation.target.src === selectedImageSrc) {
                    continue;
                }

                const newImageSrc = mutation.target.src;
                if (selectedImageSrc.endsWith('.webp') && newImageSrc.endsWith('.webp')) {
                    if (typeof imgCallback === 'function') {
                        imgCallback();
                    }
                    selectedImageSrc = newImageSrc;
                }
            }
        });

        editor.on('init', function () {
            observer.observe(editor.getBody(), {
                childList: true,
                attributes: true,
                attributeFilter: ['src'],
                subtree: true,
                attributeOldValue: true
            });

            // 插入图片后保留项目使用的快速保存回调，不清空撤销记录。
            editor.on('SetContent', function (e) {
                if (e.content && e.content.includes('<img') && typeof imgCallback === 'function') {
                    tinymce.triggerSave(); // 更新隐藏的 textarea 内容
                    setTimeout(imgCallback, 1000);
                }
            });
        });
    };

    opts.file_picker_callback = function (callback, value, meta) {
        if (meta.filetype === 'image') {
            var input = document.createElement('input');
            input.setAttribute('type', 'file');
            input.setAttribute('accept', 'image/*');

            input.onchange = function () {
                var file = this.files[0];
                if (file.size > fileSize) { // 限制为 2MB
                    alert(alertContent);
                    return;
                }
                var reader = new FileReader();
                reader.onload = function () {
                    callback(reader.result, {
                        alt: file.name
                    });
                };
                reader.readAsDataURL(file);
            };

            input.click();
        }
    };

    //粘贴时处理
    opts.paste_postprocess = function(plugin, args) {
         // 清理表格里粘贴带来的 width/style，避免列宽被锁死
        args.node.querySelectorAll('table').forEach(t => {
            t.removeAttribute('style');
            t.querySelectorAll('td,th').forEach(cell => {
                cell.removeAttribute('width');
                cell.removeAttribute('style');
            });
        });

        // 处理长链接文本：只改显示文本，不改 href
        args.node.querySelectorAll('a').forEach(a => {
             // 如果 a 标签的祖先中有 table，才处理
            if (a.closest('table')) {
                const t = a.textContent || '';
                if (t.length > 40) {
                    let softened = t
                    .replace(/([/.:?&=#_-])/g, '$1\u200b')   // 分隔符后可断
                    .replace(/(\w{40})/g, '$1\u200b');       // 连续40字符强插软断点
                    a.textContent = softened;
                    a.style.whiteSpace = 'normal';
                    a.style.overflowWrap = 'anywhere';
                    a.style.wordBreak = 'break-all';
                }
            }
        });
    }

    
    if (! opts.init_instance_callback) {
        opts.init_instance_callback = function (editor) {
            editor.on('Change', function(e) {
                $this.val(String(e.target.getContent()).replace('<p><br data-mce-bogus="1"></p>', '').replace('<p><br></p>', ''));
            });
        }
    }

    tinymce.init(opts);
</script>
