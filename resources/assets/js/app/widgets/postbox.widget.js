// ===========================================================================
// Purpose          : Posts
// Contributors     : jaw-sh
// Widget Version   : 2
// ===========================================================================

(function (window, $, undefined) {
    // Widget blueprint
    var blueprint = ib.getBlueprint();

    var options = {
        password : {
            type : "text",
            initial : ib.randomString(8),
        }
    };

    // Dropzone instance.
    blueprint.prototype.dropzone = null;

        // jQuery UI bind indicators
    blueprint.prototype.resizable = false;
    blueprint.prototype.draggable = false;
    blueprint.prototype.axis      = ib.ltr ? "sw" : "se";

    // Other widget instances.
    blueprint.prototype.notices = null;

    // Number of uploads running.
    // Used to prevent premature form submission.
    blueprint.prototype.activeUploads = 0;

    // The default values that are set behind init values.
    blueprint.prototype.defaults = {
        checkFileUrl  : window.app.board_url + "/check-file",

        // Selectors for finding and binding elements.
        selector : {
            'widget'          : "#post-form",
            'notices'         : "[data-widget=notice]:first",
            'autoupdater'     : ".autoupdater:first",

            'form-open'       : ".post-form-open",

            'dropzone'        : ".dz-container",

            'submit-post'     : "#submit-post",

            // This is the main postbox password.
            'password'        : "#password",
            // This is any field that uses the same password.
            'post-password'   : ".post-password",

            'form-fields'     : ".form-fields",
            'form-body'       : "#body",
            'form-clear'      : "#subject, #body, #captcha",
            'form-spoiler'    : ".dz-spoiler-check",

            'captcha'         : ".captcha",
            'captcha-row'     : ".row-captcha",
            'captcha-widget'  : "[data-widget='captcha']",
            'captcha-field'   : ".field-control",

            'button-close'    : ".menu-icon-close",
            'button-maximize' : ".menu-icon-maximize",
            'button-minimize' : ".menu-icon-minimize"
        },

        template : {
            'counter'         : "<tt id=\"body-counter\"></tt>",
        },

        dropzone : {
            // Localization strings.
            // dictDefaultMessage: "Drop files here to upload",
            // dictFallbackMessage: "Your browser does not support drag'n'drop file uploads.",
            // dictFallbackText: "Please use the fallback form below to upload your files like in the olden days.",
            // dictFileTooBig: "File is too big ({{filesize}}MiB). Max filesize: {{maxFilesize}}MiB.",
            // dictInvalidFileType: "You can't upload files of this type.",
            // dictResponseError: "Server responded with {{statusCode}} code.",
            // dictCancelUpload: "Cancel upload",
            // dictCancelUploadConfirmation: "Are you sure you want to cancel this upload?",
            // dictRemoveFile: "Remove file",
            // dictRemoveFileConfirmation: null,
            // dictMaxFilesExceeded: "You can not upload any more files.",

            // The input field name.
            paramName      : "files",

            // File upload URL
            url            : window.app.board_url + "/upload-file",

            // Allow multiple uploads.
            uploadMultiple : true,

            // Maximum filesize (MB)
            maxFilesize    : window.app.settings.attachmentFilesize / 1024,

            // https://www.dropzonejs.com/#config-clickable
            clickable : true,

            // Binds the instance to our widget.
            init: function () {
                var widget = this.options.widget;

                widget.dropzone = this;
                this.widget     = widget;
                this.$widget    = widget.$widget;

                $(this.element).append("<input type=\"hidden\" name=\"dropzone\" value=\"1\" />");
            },

            // Handles the acceptance of files.
            accept : function (file, done) {
                var widget  = this.widget;
                var $widget = this.$widget;
                var reader  = new FileReader();

                widget.$widget.trigger('fileUploading', [ file ]);

                reader.onload = function (event) {
                    var hash = sha256(event.target.result);
                    file.hash = hash;

                    jQuery.get( widget.options.checkFileUrl, { 'sha256' : hash })
                    .done(function (data, textStatus, jqXHR) {
                        if (typeof data !== "object") {
                            console.log("SHA-256 file check received weird response:", data);
                            return;
                        }

                        var response = data;
                        jQuery.each(response, function (index, datum) {
                            // Make sure this datum is for our file.
                            if (index !== hash) {
                                return true;
                            }

                            // Does this file exist?
                            if (datum !== null) {
                                // Is the file banned?
                                if (datum.banned_at) {
                                    // Language
                                    console.log("File "+file.name+" is banned from being uploaded.");

                                    file.status = Dropzone.ERROR;
                                    widget.dropzone.emit("error", file, "File <tt>"+file.name+"</tt> is banned from being uploaded", jqXHR);
                                    widget.dropzone.emit("complete", file);
                                }
                                else {
                                    console.log("File "+file.name+" already exists.");

                                    file.status = window.Dropzone.SUCCESS;
                                    widget.dropzone.emit("success", file, datum, jqXHR);
                                    widget.dropzone.emit("complete", file);
                                }
                            }
                            // If no presence, upload anew.
                            else {
                                console.log("Uploading file "+file.name+".");

                                done();
                            }
                        });
                    });
                };

                reader.readAsArrayBuffer(file);
            },

            canceled : function (file) {
                var $widget = this.$widget;
                $widget.trigger('fileCanceled', [ file ]);
            },

            error : function (file, message, xhr) {
                var widget  = this.widget;
                var $widget = this.$widget;

                if (typeof message.message !== 'undefined') {
                    widget.notices.push(message.message, 'error');
                }
                else {
                    widget.notices.push(message, 'error');
                }

                $(file.previewElement).remove();

                $widget.trigger('fileFailed', [ file ]);
            },

            removedfile : function (file) {
                var widget = this.widget;
                var _ref;

                if (file.previewElement) {
                    if ((_ref = file.previewElement) != null) {
                        _ref.parentNode.removeChild(file.previewElement);
                    }
                }

                widget.resizePostbox();

                return this._updateMaxFilesReachedClass();
            },

            success : function (file, response, xhr) {
                var widget  = this.widget;
                var $widget = this.$widget;

                if (typeof response !== "object")
                {
                    var response = jQuery.parseJSON(response);

                    // Get the autoupdater to clear its last seen message since we're in the thread reading it.
                    var el = document.getElementsByClassName('autoupdater')[0];
                    if (el) {
                        el.widget.builclearLastSeendWatchlist();
                    }
                }

                if (typeof response.errors !== "undefined")
                {
                    jQuery.each(response.errors, function (field, errors)
                    {
                        jQuery.each(errors, function (index, error)
                        {
                            widget.dropzone.emit("error", file, error, xhr);
                            widget.dropzone.emit("complete", file);
                        });
                    });
                }
                else
                {
                    var $preview = $(file.previewElement);

                    $preview
                        .addClass('dz-success')
                        .append($("<input type=\"hidden\" />").attr('name', widget.options.dropzone.paramName+"[hash][]").val(file.hash))
                        .append($("<input type=\"hidden\" />").attr('name', widget.options.dropzone.paramName+"[name][]").val(file.name))
                    ;

                    $("[data-dz-spoiler]", $preview)
                        .attr('name', widget.options.dropzone.paramName+"[spoiler][]");
                }

                $widget.trigger('fileUploaded', [ file ]);
            },

            previewTemplate :
                "<div class=\"dz-preview dz-file-preview\">" +
                    "<div class=\"dz-image\">" +
                        "<img data-dz-thumbnail />" +
                    "</div>" +
                    "<div class=\"dz-actions\">" +
                        "<span class=\"dz-remove\" data-dz-remove>x</span>" +
                        "<label class=\"dz-spoiler\">" +
                            "<input type=\"checkbox\" class=\"dz-spoiler-check\" name=\"\" value=\"\" />" +
                            "<input type=\"chidden\" class=\"dz-spoiler-hidden\" value=\"0\" data-dz-spoiler />" +
                            "<span class=\"dz-spoiler-desc\">Spoiler</span>" +
                        "</label>" +
                    "</div>" +
                    "<div class=\"dz-details\">" +
                        "<div class=\"dz-size\"><span data-dz-size></span></div>" +
                        "<div class=\"dz-filename\"><span data-dz-name></span></div>" +
                    "</div>" +
                    "<div class=\"dz-progress\"><span class=\"dz-upload\" data-dz-uploadprogress></span></div>" +
                    "<div class=\"dz-success\">" +
                        "<div class=\"dz-success-mark\">" +
                            "<svg viewBox=\"0 0 54 54\" version=\"1.1\" xmlns=\"http://www.w3.org/2000/svg\" xmlns:xlink=\"http://www.w3.org/1999/xlink\" xmlns:sketch=\"http://www.bohemiancoding.com/sketch/ns\">" +
                                "<g id=\"Page-1\" stroke=\"none\" stroke-width=\"1\" fill=\"none\" fill-rule=\"evenodd\" sketch:type=\"MSPage\">" +
                                    "<path d=\"M23.5,31.8431458 L17.5852419,25.9283877 C16.0248253,24.3679711 13.4910294,24.366835 11.9289322,25.9289322 C10.3700136,27.4878508 10.3665912,30.0234455 11.9283877,31.5852419 L20.4147581,40.0716123 C20.5133999,40.1702541 20.6159315,40.2626649 20.7218615,40.3488435 C22.2835669,41.8725651 24.794234,41.8626202 26.3461564,40.3106978 L43.3106978,23.3461564 C44.8771021,21.7797521 44.8758057,19.2483887 43.3137085,17.6862915 C41.7547899,16.1273729 39.2176035,16.1255422 37.6538436,17.6893022 L23.5,31.8431458 Z M27,53 C41.3594035,53 53,41.3594035 53,27 C53,12.6405965 41.3594035,1 27,1 C12.6405965,1 1,12.6405965 1,27 C1,41.3594035 12.6405965,53 27,53 Z\" " +
                                        "id=\"Oval-2\" " +
                                        "stroke-opacity=\"0.198794158\" " +
                                        "stroke=\"#747474\" " +
                                        "fill-opacity=\"0.816519475\" " +
                                        "fill=\"#FFFFFF\" " +
                                        "sketch:type=\"MSShapeGroup\" " +
                                    "></path>" +
                                "</g>" +
                            "</svg>" +
                        "</div>" +
                    "</div>" +
                "</div>"
        }
    };

    // Compiled settings.
    blueprint.prototype.options = false;

    blueprint.prototype.hasCaptcha = function () {
        return $(this.options.selector['captcha-row'], this.$widget).is(":visible");
    };

    blueprint.prototype.responsiveAnchor = function ($elem) {
        if (!ib.isMobile()) {
            return false;
        }

        var widget  = this;
        var $widget = this.$widget;
        $elem = $($elem);

        $widget.show()
            .toggleClass('postbox-closed', false)
            .toggleClass('postbox-maximized', false)
            .toggleClass('postbox-minimized', false);

        var top = $elem.position().top - $widget.outerHeight() - 10;

        $widget.css({
            'top' : top
        });

        var offset = $widget.offset().top;
        if (offset < 10) {
            top -= offset;

            $widget.css({
                'top' : top,
            });
        }

        return top;
    };

    blueprint.prototype.resizePostbox = function () {
        var widget  = this;
        var $widget = this.$widget;

        if (widget.resizable)
        {
            if (ib.isMobile())
            {
                widget.unbindDraggable();
                widget.unbindResize();
            }
            else
            {
                // Trigger resize on the post body.
                // Forces the post box to obey new window constraints.
                var $post    = $(widget.options.selector['form-body'], widget.$widget);
                var uiWidget = $post.data('ui-resizable');

                // Widget is bound and we have data
                if (uiWidget && !jQuery.isEmptyObject(uiWidget.prevPosition))
                {
                    // This is copy+pasted from the source code because there is no polite way
                    // to handle it otherwise.
                    uiWidget._updatePrevProperties();
                    uiWidget._trigger( "resize", event, uiWidget.ui() );
                    uiWidget._applyChanges();
                }
            }
        }
        else if (!ib.isMobile())
        {
            var isClosed = $widget.hasClass("postbox-closed");
            var isMaximized = $widget.hasClass("postbox-maximized");

            if (!isMaximized && !isClosed)
            {
                widget.bindResize();
            }

            if (!isMaximized)
            {
                widget.bindDraggable();
            }
        }
    };

    // Gets the selection range of our post box.
    blueprint.prototype.getBodySelection = function () {
        var el = $(this.options.selector['form-body'], this.$widget)[0];
        var start = 0;
        var end = 0;
        var normalizedValue;
        var range;
        var textInputRange;
        var len;
        var endRange;

        if (typeof el.selectionStart == "number" && typeof el.selectionEnd == "number") {
            start = el.selectionStart;
            end = el.selectionEnd;
        }
        else if (typeof document.selection !== "undefined") {
            range = document.selection.createRange();

            if (range && range.parentElement() == el) {
                len = el.value.length;
                normalizedValue = el.value.replace(/\r\n/g, "\n");

                // Create a working TextRange that lives only in the input
                textInputRange = el.createTextRange();
                textInputRange.moveToBookmark(range.getBookmark());

                // Check if the start and end of the selection are at the very end
                // of the input, since moveStart/moveEnd doesn't return what we want
                // in those cases
                endRange = el.createTextRange();
                endRange.collapse(false);

                if (textInputRange.compareEndPoints("StartToEnd", endRange) > -1) {
                    start = end = len;
                }
                else {
                    start = -textInputRange.moveStart("character", -len);
                    start += normalizedValue.slice(0, start).split("\n").length - 1;

                    if (textInputRange.compareEndPoints("EndToEnd", endRange) > -1) {
                        end = len;
                    }
                    else {
                        end = -textInputRange.moveEnd("character", -len);
                        end += normalizedValue.slice(0, end).split("\n").length - 1;
                    }
                }
            }
        }

        return {
            start: start,
            end: end
        };
    }

    // Replaces selected text with new text in our post box.
    blueprint.prototype.replaceBodySelection = function (text) {
        var $textarea = $(this.options.selector['form-body'], this.$widget);
        var textarea  = $textarea[0];
        var selection = this.getBodySelection();

        var value     = textarea.value;
        var front     = value.substring(0, selection.start);
        var back      = value.substring(selection.end, value.length);

        $textarea.val(front + text + back);

        strPos = selection.start + text.length;

        if (typeof textarea.selectionStart == "number" && typeof textarea.selectionEnd == "number") {
            textarea.selectionStart = strPos;
            textarea.selectionEnd = strPos;
            textarea.focus();
        }
        else if (typeof document.selection != "undefined") {
            textarea.focus();
            var range = document.selection.createRange();
            range.moveStart('character', -textarea.value.length);
            range.moveStart('character', strPos);
            range.moveEnd('character', 0);
            range.select();
        }
    }

    // Events
    blueprint.prototype.events = {
        bodyChange    : function (event) {
            var widget  = event.data.widget;
            var $widget = event.data.$widget;

            if (widget.$counter && widget.$counter instanceof jQuery)
            {
                var $body = $(this);
                var len   = $body.val().length;
                var text  = "<strong id=\"body-counter-curr\">" + len + "</strong>";
                var valid = true;
                var free  = true;
                var max   = parseInt(window.app.board_settings.postMaxLength, 10);
                var min   = parseInt(window.app.board_settings.postMinLength, 10);

                if (!isNaN(max))
                {
                    text  = text + "<span id=\"body-counter-max\">" + max + "</span>";
                    free  = false;
                    valid = valid && (len <= max);
                }

                if (!isNaN(min))
                {
                    text  = "<span id=\"body-counter-min\">" + min + "</span>" + text;
                    free  = false;
                    valid = valid && (len >= min);
                }

                if (!free)
                {
                    widget.$counter
                        .toggleClass("counter-valid",    valid)
                        .toggleClass("counter-invalid", !valid)
                        .html(text);
                }
            }
        },

        captchaHide   : function (widget) {
            var $widget = widget.$widget;

            $(widget.options.selector['captcha-row'], $widget).hide();
        },

        captchaShow   : function (widget) {
            var $widget = widget.$widget;

            $(widget.options.selector['captcha-row'], $widget).show();
        },

        closeClick    : function (event) {
            var widget  = event.data.widget;
            var $widget = event.data.$widget;

            // Tweak classes.
            $widget
                .removeClass("postbox-maximized postbox-minimized")
                .addClass("postbox-closed");

            if (ib.isMobile()) {
                $widget.hide();
            }

            // Unbind the jQuery UI resize.
            widget.unbindResize();

            // Prevents formClick from immediately firing.
            event.stopPropagation();
        },

        fileUploading : function (event, file) {
            var widget  = event.data.widget;
            var $widget = event.data.$widget;

            ++widget.activeUploads;
            console.log(widget.activeUploads + " concurrent uploads.");

            $(widget.options.selector['submit-post'], $widget)
                .prop('disabled', widget.activeUploads > 0);
        },

        fileCanceled  : function (event, file) {
            var widget  = event.data.widget;
            var $widget = event.data.$widget;

            --widget.activeUploads;
            console.log(widget.activeUploads + " concurrent uploads.");

            $(widget.options.selector['submit-post'], $widget)
                .prop('disabled', widget.activeUploads > 0);
        },

        fileFailed    : function (event, file) {
            var widget  = event.data.widget;
            var $widget = event.data.$widget;

            --widget.activeUploads;
            console.log(widget.activeUploads + " concurrent uploads.");

            $(widget.options.selector['submit-post'], $widget)
                .prop('disabled', widget.activeUploads > 0);
        },

        fileUploaded  : function (event, file) {
            var widget  = event.data.widget;
            var $widget = event.data.$widget;

            --widget.activeUploads;
            console.log(widget.activeUploads + " concurrent uploads.");

            $(widget.options.selector['submit-post'], $widget)
                .prop('disabled', widget.activeUploads > 0);
        },

        formClear     : function (event) {
            var widget  = event.data.widget;
            var $widget = event.data.$widget;

            // Stops redundant loading of captcha when we don't need one.
            if (widget.hasCaptcha())
            {
                $(widget.options.selector['captcha'], $widget).trigger('reload');
            }

            if (widget.dropzone)
            {
                widget.dropzone.removeAllFiles();
            }

            $(widget.options.selector['form-clear'], $widget)
                .val("")
                .html("");

            $(widget.options.selector['form-body'], $widget)
                .trigger('change')
                .focus();

            if (ib.isMobile()) {
                $widget.css("display", "");
            }
        },

        formClick     : function (event) {
            var widget  = event.data.widget;
            var $widget = event.data.$widget;

            if ($widget.is(".postbox-closed")) {
                // Tweak classes.
                $widget.removeClass("postbox-minimized postbox-closed postbox-maximized");

                // Rebind jQuery UI widgets.
                widget.bindDraggable();
                widget.bindResize();
            }
        },

        formOpenClick : function (event) {
            var widget  = event.data.widget;
            var $widget = event.data.$widget;

            widget.responsiveAnchor(this);

            // Tweak classes.
            $widget.removeClass("postbox-minimized postbox-closed postbox-maximized");

            // Rebind jQuery UI widgets.
            widget.bindDraggable();
            widget.bindResize();

            // focus the input field
            setTimeout(function () {
                var $textarea = $(widget.options.selector['form-body'], $widget);
                $textarea.focus();
                $textarea[0].selectionStart = $textarea[0].selectionEnd = $textarea[0].value.length;
            }, 100);

        },

        formPaste     : function (event) {
            var items = (event.clipboardData || event.originalEvent.clipboardData).items;

            for (index in items) {
                var item = items[index];
                if (item.kind === 'file') {
                    // adds the file to your dropzone instance
                    event.data.widget.dropzone.addFile(item.getAsFile());
                }
            }
        },

        formSubmit    : function (event) {
            var widget  = event.data.widget;
            var $widget = event.data.$widget;

            widget.notices.clear();

            var $form       = $(this).add("<input name=\"messenger\" value=\"1\" />");
            var $updater    = $(widget.options.selector['autoupdater']);
            var autoupdater = false;

            // Note: serializeJSON is a plugin we use to convert form data into
            // a multidimensional array for application/json posts.

            if ($updater.length && $updater[0].widget) {
                var autoupdater = $updater[0].widget;

                //if (autoupdater.updateWs) {
                //    var data = $form
                //        .add("<input name=\"updateWs\" value=\"1\" />")
                //        .serializeJSON();
                //}
                //else {
                    var data = $form
                        .add("<input name=\"updatesOnly\" value=\"1\" />")
                        .add("<input name=\"updateHtml\" value=\"1\" />")
                        .add("<input name=\"updatedSince\" value=\"" + autoupdater.updateLast +"\" />")
                        .serializeJSON();
                //}
            }
            else {
                var data = $form.serializeJSON();
            }

            // Indicate we want a full messenger response.
            data.messenger = true;

            // Temporarialy disable form and submit button to prevent double posting
            $form.prop('disabled', true);
            $(widget.options.selector['submit']).prop('disabled', true);

            jQuery.ajax({
                type:        "POST",
                method:      "PUT",
                url:         $form.attr('action') + ".json",
                data:        data,
                dataType:    "json",
                contentType: "application/json; charset=utf-8"
            })
                .always(function (response, textStatus, jqXHR) {
                    $form.prop('disabled', false);
                    $(widget.options.selector['submit']).prop('disabled', false);

                    if (typeof response !== "object") {
                        try {
                            response = jQuery.parseJSON(response);
                        }
                        catch (exception) {
                            console.log("Post submission returned unpredictable response. Refreshing.");
                            window.location.reload();
                            return;
                        }
                    }

                    // This event trigger will cascade effects with our supplemental Messenger information.
                    if (response.messenger) {
                        $(window).trigger('messenger', response);
                        var json = response.data;
                    }
                    else if (typeof response.responseJSON !== undefined){
                        var json = response.responseJSON;
                    }
                    else {
                        var json = response;
                    }

                    if (typeof json.errors !== "undefined") {
                        console.log("Post rejected.");

                        jQuery.each(json.errors, function (field, errors) {
                            jQuery.each(errors, function (index, error) {
                                widget.notices.push(error, 'error');
                            });
                        });
                    }
                    else if (typeof json.message !== "undefined") {
                        widget.notices.push(json.message, 'message');
                    }
                    else if (typeof json.redirect !== "undefined") {
                        console.log("Post submitted. Redirecting.");
                        window.ib.storeYouPost(json.post.board_uri, json.post.board_id);
                        window.location = json.redirect;
                    }
                    else if (autoupdater !== false)
                    {
                        console.log("Post submitted. Inline updating.");

                        clearInterval(autoupdater.updateTimer);

                        jqXHR.widget = autoupdater;
                        autoupdater.updating    = true;
                        autoupdater.updateTimer = false;
                        autoupdater.updateAsked = parseInt(parseInt(Date.now(), 10) / 1000, 10);
                        autoupdater.events.updateSuccess(json, textStatus, jqXHR, true);
                        autoupdater.events.updateComplete(json, textStatus, jqXHR);

                        widget.events.formClear(event);
                    }
                    else
                    {
                        console.log("Post submitted. No autoupdater. Refreshing.");
                        window.location.reload();
                    }
                });

            event.preventDefault();
            return false;
        },

        maximizeClick : function (event) {
            var widget  = event.data.widget;
            var $widget = event.data.$widget;

            // Tweak classes.
            $widget
                .removeClass("postbox-minimized postbox-closed")
                .addClass("postbox-maximized");

            // Remove jQuery UI widgets.
            widget.unbindDraggable();
            widget.unbindResize();
        },

        messenger     : function (event, messages) {
            if (messages.messenger)
            {
                ib.getInstances('postbox').each(function ()
                {
                    var widget  = this.widget;
                    var $widget = widget.$widget;

                    // Toggles captcha based on messenger information.
                    if (messages.captcha)
                    {
                        var $captcha = $(widget.options.selector['captcha-widget'], $widget);
                        // Replace if we've requested a replace.
                        var replacing = widget.replacingCaptcha;

                        if (!replacing)
                        {
                            // Otherwise, replace if we've expired.
                            var expiresAt = $captcha.children('img').data('expires-at');
                            replacing = expiresAt < (new Date().getTime() / 1000);
                        }

                        if (!replacing)
                        {
                            // Otherwise, replace if our session is the same and
                            // our captcha has changed. This would mean that our
                            // captcha has been invalidated somehow. We don't
                            // normally replace a captcha because if we're using
                            // Tor we never keep a session.
                            replacing = window.ib.session === messages.session;
                        }

                        if (replacing)
                        {
                            widget.replacingCaptcha = false;
                            widget.events.captchaShow(widget);
                            $captcha.trigger('load', [ messages.captcha ]);
                        }
                    }
                    else
                    {
                        widget.events.captchaHide(widget);
                    }
                });
            }
        },

        minimizeClick : function (event) {
            var widget  = event.data.widget;
            var $widget = event.data.$widget;

            // Tweak classes.
            $widget
                .removeClass("postbox-maximized postbox-closed")
                .addClass("postbox-minimized");

            // Rebind jQuery UI Resize.
            widget.bindDraggable();
            widget.bindResize();
        },

        pageChange    : function (event) {
            widget.options.checkFileUrl = window.app.board_url + "check-file";
            widget.dropzone.options.url = window.app.board_url + "upload-file";
            widget.dropzone.options.maxFilesize = window.app.settings.attachmentFilesize / 1024;
        },

        postDragStop  : function (event, ui) {
            var widget  = this.widget;
            var $widget = this.widget.$widget;

            if (1)// || ib.ltr && widget.axis == "sw")
            {
                // Okay, so:
                // Our styling using top,right.
                // Draggable sets the position using top,left.
                // This causes the box to expand to the right when dragging with the resize handles.
                // Because you cannot drag to expand and drag to move at the same time, it's safe
                // to fix the right/left assignent after the drag has stopped.
                // This uses little jQuery as well which is just gravy.
                var rect  = this.getBoundingClientRect();
                var right = (document.body.clientWidth - rect.right);

                if (rect.top <= 80 && right <= 40)
                {
                    right = 10;
                    this.style.top = 45 + "px";
                }

                this.style.height = "auto";
                this.style.left   = "auto";
                this.style.right  = right + "px";
            }
        },

        postKeyDown  : function (event) {
            var widget  = event.data.widget;
            var $widget = event.data.$widget;

            // Captures CTRL+ENTER
            if ((event.keyCode == 10 || event.keyCode == 13) && event.ctrlKey)
            {
                $(widget.options.selector['submit-post'], $widget)
                    .trigger('click');

                event.preventDefault();
                return false;
            }
        },

        postResize    : function (event, ui) {
            var widget  = this.widget;
            var $widget = this.widget.$widget;

            var $post = $(this);

            ui.position.top  = 0;
            ui.position.left = 0;

            var formHangY   = window.innerHeight - ($widget.position().top + widget.$widget.outerHeight());
            ui.size.width   = Math.min(ui.size.width, $widget.width());
            ui.size.height += Math.min(0, formHangY);

            widget.$widget.css({
                'height' : formHangY > 0 ? "auto" : window.innerHeight - $widget.position().top
            });

            $post.css('width', ui.size.width);
            $post.children().first().css('width', "100%");

            return ui;
        },

        postResizeStart : function (event, ui) {
            var widget  = this.widget;
            var $widget = this.widget.$widget;
            var axis    = $(this).data('ui-resizable').axis;

            if (widget.axis != axis)
            {
                var rect  = this.getBoundingClientRect();

                if (1)//widget.axis == "sw")
                {
                    $widget[0].style.left  = rect.left + "px";
                    $widget[0].style.right = "auto";
                }
            }
        },

        postResizeStop  : function (event, ui) {
            var widget  = this.widget;
            var $widget = this.widget.$widget;
            var axis    = $(this).data('ui-resizable').axis;

            if (widget.axis != axis)
            {
                var rect  = this.getBoundingClientRect();

                if (1)//widget.axis == "sw")
                {
                    var right = (document.body.clientWidth - rect.right);

                    $widget[0].style.left  = "auto";
                    $widget[0].style.right = right + "px";
                }
            }
        },

        spoilerChange : function (event) {
            var $this = $(this);
            var $next = $this.next();

            $this.next().attr('value', $this.prop('checked') ? 1 : 0);
        },

        windowResize  : function (event) {
            // For some pathetic reason, the jQery UI Resize widget uses the "resize"
            // event name, which is also an HTML default for window resizes. Events fired
            // also bubble up to the window, so this gets called when the post box resizes too.
            if (event.target === window)
            {
                event.data.widget.resizePostbox();
            }
        }
    },

    // Event bindings
    blueprint.prototype.bind = function () {
        var widget  = this;
        var $widget = this.$widget;
        var data    = {
            widget  : widget,
            $widget : $widget
        };

        $(widget.options.selector['password'], $widget)
            .val(ib.settings.postbox.password.get());

        // Force the notices widget to be bound, and then record it.
        // We have to do this because the notices widget is a child within this widget.
        // The parent is bound first.
        widget.notices = window.ib.bindElement($(widget.options.selector['notices'])[0]);

        if (typeof window.Dropzone !== 'undefined'){
            var dropzoneOptions = jQuery.extend({}, widget.options.dropzone);
            dropzoneOptions.widget  = widget;
            dropzoneOptions.$widget = $widget;

            $(widget.options.selector['dropzone'], $widget)
                .dropzone(dropzoneOptions);
        }

        $(window)
            .on('messenger.ib-postbox.', data, widget.events.messenger)
            .on('resize.ib-postbox',     data, widget.events.windowResize)
        ;

        // This will actually bind multiple times so make sure it only happens once.
        if (widget.initOnce !== true)
        {
            // Ensures window.app is current with dropzone stuff.
            //InstantClick.on("change", data, widget.events.pageChange);
        }

        // Post form open buttons outside the form itself
        $(document).on('click.ib-postbox', widget.options.selector['form-open'], data, widget.events.formOpenClick);

        $widget
            .on('open-form.ib-postbox', data, widget.events.formOpenClick)

            // Watch for key downs as to capture ctrl+enter submission.
            // We don't die this to any particular item.
            .on('keydown.ib-postbox', data, widget.events.postKeyDown)

            // Watch for form size clicks
            // .on('click.ib-postbox', data, widget.events.formClick) // I don't know what this does and it causes errors.
            .on('paste.ib-postbox', data, widget.events.formPaste)
            .on('click.ib-postbox', widget.options.selector['button-close'], data, widget.events.closeClick)
            .on('click.ib-postbox', widget.options.selector['button-maximize'], data, widget.events.maximizeClick)
            .on('click.ib-postbox', widget.options.selector['button-minimize'], data, widget.events.minimizeClick)

            // Watch field changes
            .on('change.ib-postbox', widget.options.selector['form-body'], data, widget.events.bodyChange)
            .on('keyup.ib-postbox', widget.options.selector['form-body'], data, widget.events.bodyChange)
            .on('change.ib-postbox', widget.options.selector['form-spoiler'], data, widget.events.spoilerChange)

            // Watch form submission.
            .on('submit.ib-postbox', data, widget.events.formSubmit)

            // Watch for file statuses.
            .on('fileFailed.ib-postbox', data, widget.events.fileFailed)
            .on('fileCanceled.ib-postbox', data, widget.events.fileCanceled)
            .on('fileUploaded.ib-postbox', data, widget.events.fileUploaded)
            .on('fileUploading.ib-postbox', data, widget.events.fileUploading)
        ;

        // Insert our first cite if we load with #post-333 as hash.
        var citeMatch = window.location.hash.match(/^#*reply-(\d+)$/);
        if (citeMatch !== null)
        {
            var $body = $(widget.options.selector['form-body']);
            $body.val($body.val() + ">>" + citeMatch[1] + "\n");
        }

        widget.bindCounter();
        widget.bindDraggable();
        widget.bindResize();
        $widget.addClass("postbox-closed");
    };

    blueprint.prototype.bindCounter = function () {
        var widget   = this;
        var $widget  = this.$widget;
        var $body    = $(widget.options.selector['form-body'], widget.$widget);
        var $counter = $(widget.options.template['counter']);

        $counter.insertAfter($body);
        widget.$counter = $counter;
        $body.trigger('change');
    };

    blueprint.prototype.bindDraggable = function () {
        var widget   = this;
        var $widget  = this.$widget;

        if (!ib.isMobile()) {
            $widget.draggable({
                containment : "window",
                handle      : ".move",
                stop        : widget.events.postDragStop
            });

            widget.draggable = true;
        }
    };

    blueprint.prototype.bindResize = function () {
        var widget   = this;
        var $widget  = this.$widget;

        if (!ib.isMobile())
        {
            // Bind resizability onto the post area.
            var $body   = $(widget.options.selector['form-body'], $widget);

            if (!widget.resizable && $body.length && typeof $body.resizable === "function")
            {
                $body.resizable({
                    handles:     "sw,se",
                    resize:      widget.events.postResize,
                    start:       widget.events.postResizeStart,
                    stop:        widget.events.postResizeStop,
                    alsoResize:  widget.$widget,
                    minWidth:    300,
                    minHeight:   26
                });

                // This gives the jQuery UI events a scope back to the widget.
                var jWidget = $body.resizable("widget")[0];
                jWidget.widget  = widget;
                jWidget.$widget = $widget;

                $widget
                    .resizable({
                        handles:  null,
                        minWidth: 300
                    })
                    .css({
                        height: "auto"
                    });

                widget.resizable = true;
            }
        }
    };

    blueprint.prototype.unbindCounter = function () {
        var widget   = this;

        if (widget.$counter && widget.$counter instanceof jQuery)
        {
            widget.$counter.remove();
        }
    };

    blueprint.prototype.unbindDraggable = function () {
        var widget   = this;
        var $widget  = this.$widget;

        if (widget.draggable && typeof $widget.draggable === "function")
        {
            $widget.draggable( "destroy" ).attr('style', "");

            widget.draggable = false;
        }
    };

    blueprint.prototype.unbindResize = function () {
        var widget   = this;
        var $widget  = this.$widget;

        // Bind resizability onto the post area.
        var $body = $(widget.options.selector['form-body'], widget.$widget);

        if (widget.resizable && $body.length && typeof $body.resizable === "function")
        {
            $body.resizable( "destroy" ).attr('style', "");

            $widget.resizable( "destroy" ).attr('style', "");

            widget.resizable = false;
        }
    };

    ib.widget("postbox", blueprint, options);
    ib.settings.postbox.password.setInitial(false);

    $(document).on('ready.ib-postbox', function (event) {
        // Bit of a hack.
        // Sets form values to our password by default even outside
        // of the scope of a thread.
        $(blueprint.prototype.defaults.selector['post-password'])
            .val(ib.settings['postbox']['password'].get());
    });
})(window, window.jQuery);
