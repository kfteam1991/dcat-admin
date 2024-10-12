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
    var alertContent = '文件大小不能超过 ' + fileSize/1024/1024 + ' MB'
    let debounceTimer; // 在外部作用域中定义 debounceTimer

    opts.selector = '#'+id;
    opts.setup = function (editor) {
        editor.on('NodeChange', function (e) {
            if (e.element.nodeName === 'IMG') {
                // 移除之前可能绑定的 keydown 事件，避免重复绑定
                editor.off('keydown').on('keydown', function (event) {
                    if (event.key === 'Delete' || event.key === 'Backspace') {
                        event.stopPropagation(); // 阻止默认行为
                        // event.preventDefault();
                        let selectedNode = editor.selection.getNode();
                        // 发送 AJAX 请求删除服务器上的图片
                        $.ajax({
                            url: '/admin/dcat-api/tinymce/delete',  // 删除图片接口
                            type: 'POST',
                            data: {
                                image: selectedNode.src,
                                _token: $('meta[name="csrf-token"]').attr('content')  // CSRF 令牌
                            },
                            success: function (response) {
                                // 删除成功后,从编辑器中移除图片
                                editor.dom.remove(selectedNode);
                            },
                            error: function (xhr) {
                                console.error('Error deleting image:', xhr);
                            }
                        });
                    }
                });
            }
        });

         // 监听图片上传对话框的打开
         editor.on('OpenWindow', function (e) {
            setTimeout(function() {
                // 使用 jQuery 监听 "取消" 按钮的点击事件
                $('.tox-button--secondary').on('click', function () {
                    // 触发删除图片逻辑
                    var url = $(this).closest('.tox-dialog__footer').prev().find('input[type="url"]').val();
                    debugger;
                    // 存在路径  并且以 http 或者 https 开头，说明已经上传到服务器了，需要删除
                    if (url.length > 0 && (url.startsWith("https://") || url.startsWith("http://"))) {
                        deleteImage(url);
                    } 
                });

                // 监听对话框右上角关闭叉号的点击事件
                $('.tox-button--icon').not('.tox-browse-url').on('click', function () {
                    debugger;
                    var url = $(this).closest('.tox-dialog__header').next().find('input[type="url"]').val();
                    // 存在路径  并且以 http 或者 https 开头，说明已经上传到服务器了，需要删除
                    if (url.length > 0 && (url.startsWith("https://") || url.startsWith("http://"))) {
                        deleteImage(url);
                    }
                });
            }, 500); // 延迟一下，确保对话框的元素已经加载完成
        });

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
                        alert(alertContent);
                        return;
                    }
                }
            }
        });

        //图片有新增、删除进行回调
        editor.on('init', function () {
            // 获取编辑器内容区域
            const editorBody = editor.getBody();

            // 创建 MutationObserver 实例
            const observer = new MutationObserver(function (mutationsList) {
                for (let mutation of mutationsList) {
                    // 处理子节点的增加或删除
                    if (mutation.type === 'childList') {
                        // 检查是否有图片新增
                        mutation.addedNodes.forEach(node => {
                            if (node.nodeName === 'IMG' && !node.__imgAdded) {
                                // 使用自定义属性标记此图片节点，防止重复添加
                                node.__imgAdded = true;
                                console.log('Image added:', node.src);
                                if (typeof imgCallback === 'function') {
                                    tinymce.triggerSave(); // 更新隐藏的 textarea 内容
                                    setTimeout(function() {//延迟调用回调函数，避免加载不完
                                        imgCallback();
                                    }, 1000);
                                }
                            }
                        });

                        // 检查是否有图片删除
                        mutation.removedNodes.forEach(node => {
                            if (node.nodeName === 'IMG' && node.__imgAdded) {
                                console.log('Image removed:', node.src);
                                if (typeof imgCallback === 'function') {
                                    tinymce.triggerSave(); // 更新隐藏的 textarea 内容
                                    setTimeout(function() {//延迟调用回调函数，避免加载不完
                                        imgCallback();
                                    }, 1000);
                                
                                }
                            }
                        });
                    }

                // 处理属性变动，只关注图片的 src 变化，排除宽高调整
                // if (mutation.type === 'attributes' && mutation.target.nodeName === 'IMG') {
                //     if (mutation.attributeName === 'src') {
                //         console.log('Image source changed:', mutation.target.src);
                //         if (typeof imgCallback === 'function') {
                //             imgCallback();
                //         }
                //     }
                // }
                }
            });

            // 开始监听编辑器内容区域的变动
            observer.observe(editorBody, {
                childList: true,      // 监听子节点的增删
                attributes: true,     // 监听属性的变化
                subtree: true         // 监听所有子节点
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
    if (! opts.init_instance_callback) {
        opts.init_instance_callback = function (editor) {
            editor.on('Change', function(e) {
                $this.val(String(e.target.getContent()).replace('<p><br data-mce-bogus="1"></p>', '').replace('<p><br></p>', ''));
            });
           
        }
    }

    tinymce.init(opts);

    //删除图片
    function deleteImage(url){
        // 发送 AJAX 请求删除服务器上的图片
        $.ajax({
            url: '/admin/dcat-api/tinymce/delete',  // 删除图片接口
            type: 'POST',
            data: {
                image: url,
                _token: $('meta[name="csrf-token"]').attr('content')  // CSRF 令牌
            }
        });
    }
</script>
