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
         // 监听图片上传对话框的打开
        editor.on('OpenWindow', function (e) {
            setTimeout(function() {
                // 使用 jQuery 监听 "取消" 按钮的点击事件
                $('.tox-button--secondary').on('click', function () {
                    // 触发删除图片逻辑
                    var url = $(this).closest('.tox-dialog__footer').prev().find('input[type="url"]').val();
                    // 存在路径  并且以 http 或者 https 开头，说明已经上传到服务器了，需要删除
                    if (url.length > 0 && selectedImageSrc != url && (url.startsWith("https://") || url.startsWith("http://"))) {
                        deleteImage(url);
                    } 
                });

                // 监听对话框右上角关闭叉号的点击事件
                $('.tox-button--icon').not('.tox-browse-url').on('click', function () {
                    var url = $(this).closest('.tox-dialog__header').next().find('input[type="url"]').val();
                    // 存在路径  并且以 http 或者 https 开头，说明已经上传到服务器了，需要删除
                    if (url != undefined && selectedImageSrc != url && url.length > 0 && (url.startsWith("https://") || url.startsWith("http://"))) {
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
                        alert(alertContent);//提示
                        return;
                    }
                }
            }
        });

   
        //图片有新增、删除进行回调
        let selectedImageSrc = null; // 记录选中的图片的 src

        // 监听编辑器中的点击事件，检查是否选中图片
        editor.on('click', function (e) {
            const target = e.target;
            if (target.nodeName === 'IMG') {
                selectedImageSrc = target.src; // 记录选中图片的 src
                console.log('Selected image src:', selectedImageSrc);
            } else {
                selectedImageSrc = null; // 如果不是图片，清空记录
            }
        });

        // 监听删除图片的操作
        editor.on('BeforeSetContent', function (e) {
            if (e.content.includes('<img')) {
                editor.undoManager.transact(function () {
                    editor.undoManager.clear();
                    // console.log('删除撤销-----');
                });
            }
        });

        // 创建 MutationObserver 实例
        const observer = new MutationObserver(function (mutationsList) {
            for (let mutation of mutationsList) {
                //替换链接时 调用
                if (mutation.type === 'attributes' && mutation.target.nodeName === 'IMG' && mutation.attributeName === 'src' && selectedImageSrc && mutation.target.src !== selectedImageSrc) {
                    const target = mutation.target;
                    const newImageSrc = target.src; // 获取新的图片 src
                    console.log('Image replaced:');
                    console.log('Old src:', selectedImageSrc);
                    console.log('New src:', newImageSrc);
                    if(selectedImageSrc.endsWith('.webp') && newImageSrc.endsWith('.webp')){
                        editor.undoManager.clear(); // 清除历史记录

                        deleteImage(selectedImageSrc);
                        if (typeof imgCallback === 'function') {
                            imgCallback();
                        }
                        // 更新选中图片的 src
                        selectedImageSrc = newImageSrc;
                    }
                }
            }
        });

        editor.on('init', function () {
            // 获取编辑器内容区域
            const editorBody = editor.getBody();
            // 开始监听编辑器内容区域的变动
            observer.observe(editorBody, {
                childList: true,      // 监听子节点的增删
                attributes: true,     // 监听属性的变化
                attributeFilter: ['src'], // 只监听指定的属性
                subtree: true,         // 监听所有子节点
                attributeOldValue: true // 捕获旧值
            });

            // 监听插入图片的操作   放到init中 目的要在初始化后生效
            editor.on('SetContent', function (e) {
                if (e.content.includes('<img')) {
                    // 清空撤销历史，防止记录图片新增操作
                    editor.undoManager.clear();
                    // console.log('插入撤销-----',e.content);
                    console.log('Image added:');
                    if (typeof imgCallback === 'function') {
                        tinymce.triggerSave(); // 更新隐藏的 textarea 内容
                        setTimeout(function() {//延迟调用回调函数，避免加载不完
                            imgCallback();
                        }, 1000);
                    }

                }
            });

            //监听节点有变化  在没有选中内容时 单独按删除按钮删除图片时
            editor.on('NodeChange', function (e) {
                // 移除之前可能绑定的 keydown 事件，避免重复绑定
                editor.off('keydown').on('keydown', function (event) {
                    if (event.key === 'Delete' || event.key === 'Backspace') {
                        const selection = editor.selection.getContent(); // 获取选中的内容
                        if(selection.length > 0){//选中删除
                            // 创建一个临时 div 解析选中的 HTML 片段
                            const tempDiv = document.createElement("div");
                            tempDiv.innerHTML = selection;

                            // 找到选中的图片
                            const images = tempDiv.querySelectorAll("img");
                            let deletedImages = [];
                            
                            images.forEach(img => {
                                editor.undoManager.clear(); // 清除历史记录
                                console.log('Deleted image src: 节点监听  选中删除', img.src); 
                                deleteImage(img.src);
                            });
                        }else{//如果没有选中则看看光标前后有无图片需要删除
                            let selection = editor.selection;
                            let rng = selection.getRng(); // 获取当前选区的 Range 对象
                            let nodeBefore = rng.startContainer.childNodes[rng.startOffset - 1]; // 前一个节点
                            let nodeAfter = rng.startContainer.childNodes[rng.startOffset]; // 后一个节点

                            if (event.key === "Backspace" && nodeBefore && nodeBefore.nodeName === "IMG") {
                                console.log("检测到 Backspace 删除了图片:", nodeBefore.src);
                                deleteImage(nodeBefore.src);
                            } else if (event.key === "Delete" && nodeAfter && nodeAfter.nodeName === "IMG") {
                                console.log("检测到 Delete 删除了图片:", nodeAfter.src);
                                deleteImage(nodeAfter.src);
                            }
                        }

                    }
                });
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
            },
            success: function (response) {
                console.log(response.message);
            },
        });
    }
</script>
