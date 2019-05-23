var Editor=function(t){var e={};function i(a){if(e[a])return e[a].exports;var n=e[a]={i:a,l:!1,exports:{}};return t[a].call(n.exports,n,n.exports,i),n.l=!0,n.exports}return i.m=t,i.c=e,i.d=function(t,e,a){i.o(t,e)||Object.defineProperty(t,e,{enumerable:!0,get:a})},i.r=function(t){"undefined"!=typeof Symbol&&Symbol.toStringTag&&Object.defineProperty(t,Symbol.toStringTag,{value:"Module"}),Object.defineProperty(t,"__esModule",{value:!0})},i.t=function(t,e){if(1&e&&(t=i(t)),8&e)return t;if(4&e&&"object"==typeof t&&t&&t.__esModule)return t;var a=Object.create(null);if(i.r(a),Object.defineProperty(a,"default",{enumerable:!0,value:t}),2&e&&"string"!=typeof t)for(var n in t)i.d(a,n,function(e){return t[e]}.bind(null,n));return a},i.n=function(t){var e=t&&t.__esModule?function(){return t.default}:function(){return t};return i.d(e,"a",e),e},i.o=function(t,e){return Object.prototype.hasOwnProperty.call(t,e)},i.p="",i(i.s=13)}([function(t,e,i){"use strict";function a(t,e,i,a,n,o,s,r){var u,d="function"==typeof t?t.options:t;if(e&&(d.render=e,d.staticRenderFns=i,d._compiled=!0),a&&(d.functional=!0),o&&(d._scopeId="data-v-"+o),s?(u=function(t){(t=t||this.$vnode&&this.$vnode.ssrContext||this.parent&&this.parent.$vnode&&this.parent.$vnode.ssrContext)||"undefined"==typeof __VUE_SSR_CONTEXT__||(t=__VUE_SSR_CONTEXT__),n&&n.call(this,t),t&&t._registeredComponents&&t._registeredComponents.add(s)},d._ssrRegister=u):n&&(u=r?function(){n.call(this,this.$root.$options.shadowRoot)}:n),u)if(d.functional){d._injectStyles=u;var c=d.render;d.render=function(t,e){return u.call(e),c(t,e)}}else{var l=d.beforeCreate;d.beforeCreate=l?[].concat(l,u):[u]}return{exports:t,options:d}}i.d(e,"a",function(){return a})},function(t,e,i){"use strict";i.r(e);var a=i(2),n=i.n(a);for(var o in a)"default"!==o&&function(t){i.d(e,t,function(){return a[t]})}(o);e.default=n.a},function(t,e,i){var a=UIkit.util;t.exports={props:["type","value","options","mode"],data:function(){return{editor:{},height:500,active:0,ready:!1,full:!1,show:!1,content:this.value}},watch:{value:function(t){this.$set(this,"content",t)},content:function(t){this.$emit("input",t),this.$emit("update:editor",t)}},created:function(){this.$on("hook:mounted",this.init)},mounted:function(){var t=this;"combine"==this.mode&&(this.tab=UIkit.switcher(this.$refs.tab),UIkit.util.on(this.tab.connects,"show",function(e,i){if(i!=t.tab)return!1;for(var n in i.toggles)if(a.closest(a.$(i.toggles[n]),"li").classList.contains("uk-active")){t.active=n;break}}),this.tab.show(this.active))},methods:{init:function(){if(this.options&&this.options.height&&(this.height=this.options.height),this.$el.hasAttributes())for(var t=this.$el.attributes,e=t.length-1;e>=0;e--)"class"!=t[e].name&&(this.$refs.editor.setAttribute(t[e].name,t[e].value),this.$el.removeAttribute(t[e].name));var i=this.$options.components,a="editor-"+this.type,n=this,o=i[a]||i["editor-"+window.$pagekit.editor]||i["editor-textarea"];new(Vue.extend(o))({parent:this}).$on("ready",function(){_.forIn(n.$options.components,function(t){t.plugin&&new(Vue.extend(t))({parent:n})},this),"combine"==n.mode&&n.addCode(),n.ready=!0})},addCode:function(){new(Vue.extend(this.$options.components["editor-code"]))({parent:this})}},components:{"editor-textarea":{created:function(){this.$emit("ready"),this.$set(this.$parent,"show",!0)}},"editor-html":i(14),"editor-code":i(15),"plugin-link":i(16),"plugin-image":i(17),"plugin-video":i(18)},utils:{"image-picker":Vue.extend(i(19).default),"video-picker":Vue.extend(i(20).default),"link-picker":Vue.extend(i(21).default)}},Vue.component("v-editor",function(e){e(t.exports)})},function(t,e,i){"use strict";i.r(e);var a=i(4),n=i.n(a);for(var o in a)"default"!==o&&function(t){i.d(e,t,function(){return a[t]})}(o);e.default=n.a},function(t,e){t.exports={name:"image-picker",data:function(){return{image:{data:{src:"",alt:""}}}},mounted:function(){this.$refs.modal.open()},methods:{selected:function(t){if(t&&!this.image.data.alt){var e=t.split("/").slice(-1)[0].replace(/\.(jpeg|jpg|png|svg|gif)$/i,"").replace(/(_|-)/g," ").trim(),i=e.charAt(0).toUpperCase();this.image.data.alt=i+e.substr(1)}},close:function(){this.$destroy(!0)},update:function(){this.$emit("select",this.image),this.$refs.modal.close()}}}},function(t,e,i){"use strict";i.r(e);var a=i(6),n=i.n(a);for(var o in a)"default"!==o&&function(t){i.d(e,t,function(){return a[t]})}(o);e.default=n.a},function(t,e){t.exports={name:"video-picker",data:function(){return{video:{data:{src:"",controls:!0}}}},mounted:function(){this.$refs.modal.open()},computed:{isYoutube:function(){return!!this.video.data.src&&this.video.data.src.match(/.*(?:youtu.be\/|v\/|u\/\w\/|embed\/|watch\?v=)([^#\&\?]*).*/)},isVimeo:function(){return!!this.video.data.src&&this.video.data.src.match(/https?:\/\/(?:www\.|player\.)?vimeo.com\/(?:channels\/(?:\w+\/)?|groups\/([^\/]*)\/videos\/|album\/(\d+)\/video\/|video\/|)(\d+)(?:$|\/|\?)/)}},methods:{close:function(){this.$destroy(!0)},update:function(){this.$emit("select",this.video),this.$refs.modal.close()}}}},function(t,e,i){"use strict";i.r(e);var a=i(8),n=i.n(a);for(var o in a)"default"!==o&&function(t){i.d(e,t,function(){return a[t]})}(o);e.default=n.a},function(t,e){t.exports={data:function(){return{link:{link:"",txt:"",class:""}}},mounted:function(){this.$refs.modal.open()},methods:{close:function(){this.$destroy(!0)},update:function(){this.$emit("select",this.link),this.$refs.modal.close()}}}},function(t,e,i){"use strict";var a=function(){var t=this,e=t.$createElement,i=t._self._c||e;return i("div",{class:["pk-editor",t.mode]},["combine"==t.mode?[i("ul",{ref:"tab",staticClass:"uk-subnav uk-flex-right",attrs:{"uk-switcher":""}},[i("li",[i("a",{attrs:{href:""}},[t._v(t._s(t._f("trans")("Visual")))])]),t._v(" "),i("li",[i("a",{attrs:{href:""}},[t._v(t._s(t._f("trans")("Code")))])])]),t._v(" "),i("ul",{staticClass:"uk-switcher"},[i("li",{ref:"tinymce",staticClass:"uk-invisible"},[i("textarea",{directives:[{name:"model",rawName:"v-model",value:t.content,expression:"content"}],ref:"editor",class:{"uk-invisible":!t.show},style:{height:t.height+"px"},attrs:{autocomplete:"off"},domProps:{value:t.content},on:{input:function(e){e.target.composing||(t.content=e.target.value)}}})]),t._v(" "),i("li",[i("textarea",{directives:[{name:"model",rawName:"v-model",value:t.content,expression:"content"}],ref:"editor-code",class:{"uk-invisible":!t.show},style:{height:t.height+"px"},attrs:{autocomplete:"off"},domProps:{value:t.content},on:{input:function(e){e.target.composing||(t.content=e.target.value)}}})])])]:[i("textarea",{directives:[{name:"model",rawName:"v-model",value:t.content,expression:"content"}],ref:"editor",class:{"uk-invisible":!t.show},style:{height:t.height+"px"},attrs:{autocomplete:"off"},domProps:{value:t.content},on:{input:function(e){e.target.composing||(t.content=e.target.value)}}})]],2)},n=[];i.d(e,"a",function(){return a}),i.d(e,"b",function(){return n})},function(t,e,i){"use strict";var a=function(){var t=this,e=t.$createElement,i=t._self._c||e;return i("v-modal",{ref:"modal",attrs:{closed:t.close}},[i("form",{staticClass:" uk-form-stacked",on:{submit:function(e){return e.preventDefault(),t.update(e)}}},[i("div",{staticClass:"uk-modal-header"},[i("h2",[t._v(t._s(t._f("trans")("Add Image")))])]),t._v(" "),i("div",{staticClass:"uk-modal-body"},[i("div",{staticClass:"uk-margin"},[i("input-image",{attrs:{source:t.image.data.src,"input-class":"uk-width-1-1"},on:{"update:source":function(e){return t.$set(t.image.data,"src",e)},"image:selected":t.selected},model:{value:t.image.data.src,callback:function(e){t.$set(t.image.data,"src",e)},expression:"image.data.src"}})],1),t._v(" "),i("div",{staticClass:"uk-margin"},[i("label",{staticClass:"uk-form-label",attrs:{for:"form-src"}},[t._v(t._s(t._f("trans")("URL")))]),t._v(" "),i("div",{staticClass:"uk-form-controls"},[i("input",{directives:[{name:"model",rawName:"v-model",value:t.image.data.src,expression:"image.data.src"}],staticClass:"uk-width-1-1 uk-input",attrs:{id:"form-src",type:"text",lazy:""},domProps:{value:t.image.data.src},on:{input:function(e){e.target.composing||t.$set(t.image.data,"src",e.target.value)}}})])]),t._v(" "),i("div",{staticClass:"uk-margin"},[i("label",{staticClass:"uk-form-label",attrs:{for:"form-alt"}},[t._v(t._s(t._f("trans")("Alt")))]),t._v(" "),i("div",{staticClass:"uk-form-controls"},[i("input",{directives:[{name:"model",rawName:"v-model",value:t.image.data.alt,expression:"image.data.alt"}],staticClass:"uk-width-1-1 uk-input",attrs:{id:"form-alt",type:"text"},domProps:{value:t.image.data.alt},on:{input:function(e){e.target.composing||t.$set(t.image.data,"alt",e.target.value)}}})])])]),t._v(" "),i("div",{staticClass:"uk-modal-footer uk-text-right"},[i("button",{staticClass:"uk-button uk-button-secondary uk-modal-close",attrs:{type:"button"}},[t._v(t._s(t._f("trans")("Cancel")))]),t._v(" "),i("button",{staticClass:"uk-button uk-button-primary",attrs:{type:"submit"}},[t._v(t._s(t._f("trans")("Update")))])])])])},n=[];i.d(e,"a",function(){return a}),i.d(e,"b",function(){return n})},function(t,e,i){"use strict";var a=function(){var t=this,e=t.$createElement,i=t._self._c||e;return i("v-modal",{ref:"modal",attrs:{closed:t.close}},[i("form",{staticClass:" uk-form-stacked",on:{submit:function(e){return e.preventDefault(),t.update(e)}}},[i("div",{staticClass:"uk-modal-header"},[i("h2",[t._v(t._s(t._f("trans")("Add Video")))])]),t._v(" "),i("div",{staticClass:"uk-modal-body"},[i("div",{staticClass:"uk-margin"},[i("input-video",{attrs:{source:t.video.data.src},on:{"update:source":function(e){return t.$set(t.video.data,"src",e)}},model:{value:t.video.data.src,callback:function(e){t.$set(t.video.data,"src",e)},expression:"video.data.src"}})],1),t._v(" "),i("div",{staticClass:"uk-margin"},[i("label",{staticClass:"uk-form-label",attrs:{for:"form-src"}},[t._v(t._s(t._f("trans")("URL")))]),t._v(" "),i("div",{staticClass:"uk-form-controls"},[i("input",{directives:[{name:"model",rawName:"v-model",value:t.video.data.src,expression:"video.data.src"}],staticClass:"uk-width-1-1 uk-input",attrs:{id:"form-src",type:"text",debounce:"500"},domProps:{value:t.video.data.src},on:{input:function(e){e.target.composing||t.$set(t.video.data,"src",e.target.value)}}})])]),t._v(" "),i("div",{staticClass:"uk-child-width-1-2@s uk-grid-small uk-flex-bottom",attrs:{"uk-grid":""}},[i("div",[i("div",{staticClass:"uk-child-width-1-2 uk-grid-small",attrs:{"uk-grid":""}},[i("div",[i("label",{staticClass:"uk-form-label",attrs:{for:"form-src"}},[t._v(t._s(t._f("trans")("Width")))]),t._v(" "),i("input",{directives:[{name:"model",rawName:"v-model",value:t.video.data.width,expression:"video.data.width"}],staticClass:"uk-width-1-1 uk-input",attrs:{id:"form-width",type:"text",placeholder:t._f("trans")("auto")},domProps:{value:t.video.data.width},on:{input:function(e){e.target.composing||t.$set(t.video.data,"width",e.target.value)}}})]),t._v(" "),i("div",[i("label",{staticClass:"uk-form-label",attrs:{for:"form-src"}},[t._v(t._s(t._f("trans")("Height")))]),t._v(" "),i("input",{directives:[{name:"model",rawName:"v-model",value:t.video.data.height,expression:"video.data.height"}],staticClass:"uk-width-1-1 uk-input",attrs:{id:"form-height",type:"text",disabled:!t.isVimeo&&!t.isYoutube,placeholder:t._f("trans")("auto")},domProps:{value:t.video.data.height},on:{input:function(e){e.target.composing||t.$set(t.video.data,"height",e.target.value)}}})])])]),t._v(" "),i("div",[i("div",{staticClass:"uk-child-width-1-2 uk-grid-small uk-text-small",attrs:{"uk-grid":""}},[i("div",{staticClass:"uk-text-nowrap uk-text-truncate"},[i("label",[i("input",{directives:[{name:"model",rawName:"v-model",value:t.video.data.autoplay,expression:"video.data.autoplay"}],staticClass:"uk-checkbox",attrs:{type:"checkbox"},domProps:{checked:Array.isArray(t.video.data.autoplay)?t._i(t.video.data.autoplay,null)>-1:t.video.data.autoplay},on:{change:function(e){var i=t.video.data.autoplay,a=e.target,n=!!a.checked;if(Array.isArray(i)){var o=t._i(i,null);a.checked?o<0&&t.$set(t.video.data,"autoplay",i.concat([null])):o>-1&&t.$set(t.video.data,"autoplay",i.slice(0,o).concat(i.slice(o+1)))}else t.$set(t.video.data,"autoplay",n)}}}),t._v(" "+t._s(t._f("trans")("Autoplay")))]),i("br"),t._v(" "),i("label",{directives:[{name:"show",rawName:"v-show",value:!t.isVimeo,expression:"!isVimeo"}]},[i("input",{directives:[{name:"model",rawName:"v-model",value:t.video.data.controls,expression:"video.data.controls"}],staticClass:"uk-checkbox",attrs:{type:"checkbox"},domProps:{checked:Array.isArray(t.video.data.controls)?t._i(t.video.data.controls,null)>-1:t.video.data.controls},on:{change:function(e){var i=t.video.data.controls,a=e.target,n=!!a.checked;if(Array.isArray(i)){var o=t._i(i,null);a.checked?o<0&&t.$set(t.video.data,"controls",i.concat([null])):o>-1&&t.$set(t.video.data,"controls",i.slice(0,o).concat(i.slice(o+1)))}else t.$set(t.video.data,"controls",n)}}}),t._v(" "+t._s(t._f("trans")("Controls")))])]),t._v(" "),i("div",{staticClass:"uk-text-nowrap uk-text-truncate"},[i("label",[i("input",{directives:[{name:"model",rawName:"v-model",value:t.video.data.loop,expression:"video.data.loop"}],staticClass:"uk-checkbox",attrs:{type:"checkbox"},domProps:{checked:Array.isArray(t.video.data.loop)?t._i(t.video.data.loop,null)>-1:t.video.data.loop},on:{change:function(e){var i=t.video.data.loop,a=e.target,n=!!a.checked;if(Array.isArray(i)){var o=t._i(i,null);a.checked?o<0&&t.$set(t.video.data,"loop",i.concat([null])):o>-1&&t.$set(t.video.data,"loop",i.slice(0,o).concat(i.slice(o+1)))}else t.$set(t.video.data,"loop",n)}}}),t._v(" "+t._s(t._f("trans")("Loop")))]),i("br"),t._v(" "),i("label",{directives:[{name:"show",rawName:"v-show",value:!t.isVimeo&&!t.isYoutube,expression:"!isVimeo && !isYoutube"}]},[i("input",{directives:[{name:"model",rawName:"v-model",value:t.video.data.muted,expression:"video.data.muted"}],staticClass:"uk-checkbox",attrs:{type:"checkbox"},domProps:{checked:Array.isArray(t.video.data.muted)?t._i(t.video.data.muted,null)>-1:t.video.data.muted},on:{change:function(e){var i=t.video.data.muted,a=e.target,n=!!a.checked;if(Array.isArray(i)){var o=t._i(i,null);a.checked?o<0&&t.$set(t.video.data,"muted",i.concat([null])):o>-1&&t.$set(t.video.data,"muted",i.slice(0,o).concat(i.slice(o+1)))}else t.$set(t.video.data,"muted",n)}}}),t._v(" "+t._s(t._f("trans")("Muted")))])])])])]),t._v(" "),i("div",{directives:[{name:"show",rawName:"v-show",value:!t.isYoutube&&!t.isVimeo,expression:"!isYoutube && !isVimeo"}],staticClass:"uk-margin"},[i("span",{staticClass:"uk-form-label"},[t._v(t._s(t._f("trans")("Poster Image")))]),t._v(" "),i("div",{staticClass:"uk-form-controls"},[i("input-image",{attrs:{source:t.video.data.poster,"input-field":!1,"input-class":"uk-form-width-large"},on:{"update:source":function(e){return t.$set(t.video.data,"poster",e)}},model:{value:t.video.data.poster,callback:function(e){t.$set(t.video.data,"poster",e)},expression:"video.data.poster"}})],1)])]),t._v(" "),i("div",{staticClass:"uk-modal-footer uk-text-right"},[i("button",{staticClass:"uk-button uk-button-secondary uk-modal-close",attrs:{type:"button"}},[t._v(t._s(t._f("trans")("Cancel")))]),t._v(" "),i("button",{staticClass:"uk-button uk-button-primary",attrs:{type:"submit",disabled:!t.video.data.src}},[t._v(t._s(t._f("trans")("Update")))])])])])},n=[];i.d(e,"a",function(){return a}),i.d(e,"b",function(){return n})},function(t,e,i){"use strict";var a=function(){var t=this,e=t.$createElement,i=t._self._c||e;return i("v-modal",{ref:"modal",attrs:{closed:t.close}},[i("form",{staticClass:"uk-form-stacked",on:{submit:function(e){return e.preventDefault(),t.update(e)}}},[i("div",{staticClass:"uk-modal-header"},[i("h2",[t._v(t._s(t._f("trans")("Add Link")))])]),t._v(" "),i("div",{staticClass:"uk-modal-body"},[i("div",{staticClass:"uk-margin"},[i("label",{staticClass:"uk-form-label",attrs:{for:"form-link-title"}},[t._v(t._s(t._f("trans")("Title")))]),t._v(" "),i("div",{staticClass:"uk-form-controls"},[i("input",{directives:[{name:"model",rawName:"v-model",value:t.link.txt,expression:"link.txt"}],staticClass:"uk-width-1-1 uk-input",attrs:{id:"form-link-title",type:"text"},domProps:{value:t.link.txt},on:{input:function(e){e.target.composing||t.$set(t.link,"txt",e.target.value)}}})])]),t._v(" "),i("div",{staticClass:"uk-margin"},[i("label",{staticClass:"uk-form-label",attrs:{for:"form-link-url"}},[t._v(t._s(t._f("trans")("Url")))]),t._v(" "),i("div",{staticClass:"uk-form-controls"},[i("input-link",{attrs:{id:"form-link-url",cls:"uk-width-1-1",link:t.link.link},on:{"update:link":function(e){return t.$set(t.link,"link",e)}},model:{value:t.link.link,callback:function(e){t.$set(t.link,"link",e)},expression:"link.link"}})],1)])]),t._v(" "),i("div",{staticClass:"uk-modal-footer uk-text-right"},[i("button",{staticClass:"uk-button uk-button-default uk-modal-close",attrs:{type:"button"}},[t._v(t._s(t._f("trans")("Cancel")))]),t._v(" "),i("button",{staticClass:"uk-button uk-button-primary",attrs:{type:"submit"}},[t._v(t._s(t._f("trans")("Update")))])])])])},n=[];i.d(e,"a",function(){return a}),i.d(e,"b",function(){return n})},function(t,e,i){"use strict";i.r(e);var a=i(9),n=i(1);for(var o in n)"default"!==o&&function(t){i.d(e,t,function(){return n[t]})}(o);var s=i(0),r=Object(s.a)(n.default,a.a,a.b,!1,null,null,null);e.default=r.exports},function(t,e){var i=UIkit.util,a=i.$,n=i.$$,o=(i.on,i.css),s=i.attr,r=i.removeAttr,u=(i.addClass,i.removeClass);i.hasClass,i.find,i.findAll,i.closest;t.exports={name:"editor-tinymce",data:function(){return{plugins:[],toolbar:""}},created:function(){var t=this,e=$editor.root_url+"/app/assets/tinymce5",i="editor.tinymce.toolbar",d=_.get(this.$parent,"$vnode.data.model.expression");this.$parent.$refs.editor.parentNode||document;i=d?i+"."+d:i,this.toolbar=this.$session.get(i,0),this.$parent.editor=this,this.$asset({js:[e+"/tinymce.min.js"]}).then(function(){this.$emit("ready"),tinyMCE.baseURL=e,tinyMCE.suffix=".min",this.$parent.editor=tinyMCE.init(_.merge({skin_url:$editor.root_url+"/app/assets/tinymce5_skin",height:this.$parent.height,mode:"exact",menubar:!1,branding:!1,plugins:[t.plugins,"autolink lists charmap hr anchor media","visualblocks fullscreen","paste help"],toolbar:["formatselect bold italic bullist numlist blockquote alignleft aligncenter alignright link image media toggletoolbar | fullscreen | UNDO","underline alignjustify strikethrough hr charmap forecolor backcolor removeformat outdent indent pastetext visualblocks textPicker widgetPicker undo redo help"],fontsize_formats:"10px 11px 12px 14px 16px 18px 24px 36px 48px",toolbar_item_size:"small",forced_root_block:"",force_br_newlines:!0,force_p_newlines:"",document_base_url:Vue.url.options.root+"/",elements:[this.$parent.$refs.editor],element_format:"html",entity_encoding:"raw",verify_html:!1,setup:function(e){e.on("init",function(){t.$nextTick(function(){var i=a(".tox-tinymce .tox-toolbar > .tox-toolbar__group:nth-child(3)",e.editorContainer),n=a(".tox-tinymce .tox-toolbar > .tox-toolbar__group:nth-child(2)",e.editorContainer);UIkit.util.css(i,{height:0,padding:0,overflow:"hidden",width:"100vw"}),UIkit.util.css(n,{position:"absolute",right:0,border:"none",background:"#fff",zIndex:1}),UIkit.util.css(a(".tox-tbtn",n),{width:a(".tox-tbtn",n).offsetWidth-1,height:a(".tox-tbtn",n).offsetHeight-1}),u(t.$parent.$refs.tinymce,"uk-invisible")})}),e.ui.registry.addIcon("more",'<svg aria-hidden="true" role="img" focusable="false" class="dashicon dashicons-ellipsis" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20"><path d="M5 10c0 1.1-.9 2-2 2s-2-.9-2-2 .9-2 2-2 2 .9 2 2zm12-2c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm-7 0c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2z"></path></svg>'),e.ui.registry.addIcon("kitchensink",'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" width="20" height="20"><rect x="0" fill="none" width="20" height="20"/><g><path d="M19 2v6H1V2h18zm-1 5V3H2v4h16zM5 4v2H3V4h2zm3 0v2H6V4h2zm3 0v2H9V4h2zm3 0v2h-2V4h2zm3 0v2h-2V4h2zm2 5v9H1V9h18zm-1 8v-7H2v7h16zM5 11v2H3v-2h2zm3 0v2H6v-2h2zm3 0v2H9v-2h2zm6 0v2h-5v-2h5zm-6 3v2H3v-2h8zm3 0v2h-2v-2h2zm3 0v2h-2v-2h2z"/></g></svg>'),e.ui.registry.addToggleButton("toggletoolbar",{icon:"kitchensink",onAction:function(a){a.isActive()?(t.$session.set(i,0),s(n(".tox-tinymce .tox-toolbar > .tox-toolbar__group:nth-child(n+4)",e.editorContainer),"hidden","")):(r(n(".tox-tinymce .tox-toolbar > .tox-toolbar__group:nth-child(n+4)",e.editorContainer),"hidden"),t.$session.set(i,1)),a.setActive(!a.isActive())},onSetup:function(i){t.$nextTick(function(){t.toolbar?i.setActive(!0):s(n(".tox-tinymce .tox-toolbar > .tox-toolbar__group:nth-child(n+4)",e.editorContainer),"hidden",""),o(a(".tox-tinymce .tox-toolbar > .tox-toolbar__group:nth-child(1)",e.editorContainer),"border","none")})}})},init_instance_callback:function(e){t.tiny=e;var i=function(t){this.tiny.setContent(t||"",{format:"text"})},a=t.$watch("$parent.content",i,{immediate:!0});e.on("change",function(){a(),t.$parent.content=e.getContent(),a=t.$watch("$parent.content",i)}),e.on("undo",function(){e.fire("change")}),e.on("redo",function(){e.fire("change")}),e.on("keydown",function(e){if((e.ctrlKey||e.metaKey)&&83==e.which&&"function"==typeof t.$root.save)return e.preventDefault(),t.$root.save(),!1})},save_onsavecallback:function(){if(t.$parent.$refs.editor.form){var e=document.createEvent("HTMLEvents");e.initEvent("submit",!0,!1),t.$parent.$refs.editor.form.dispatchEvent(e)}}},$editor))})}}},function(t,e){var i=UIkit.util,a=(i.$,i.trigger),n=i.closest;t.exports={name:"editor-code",created:function(){var t=$editor.root_url+"/app/assets/codemirror";this.$asset({css:[t+"/show-hint.css",t+"/codemirror.css"],js:[t+"/codemirror.min.js"]}).then(this.init)},methods:{init:function(){var t=this,e=this.$parent.mode,i="combine"!=e?this.$parent.$refs.editor:this.$parent.$refs["editor-code"];this.editor=CodeMirror.fromTextArea(i,_.extend({mode:"htmlmixed",dragDrop:!1,autoCloseTags:!0,matchTags:!0,autoCloseBrackets:!0,matchBrackets:!0,indentUnit:4,indentWithTabs:!1,tabSize:4,lineNumbers:!0,lineWrapping:!1,extraKeys:{F11:function(t){t.setOption("fullScreen",!t.getOption("fullScreen"))},Esc:function(t){t.getOption("fullScreen")&&t.setOption("fullScreen",!1)}}},this.$parent.options)),this.editor.setSize(null,this.$parent.height),this.editor.refresh(),this.editor.on("change",function(){t.editor.save(),a(i,"input")}),this.$watch("$parent.active",function(t){"combine"==e&&1==t&&(this.editor.setSize(null,this.getHiddenHeight(this.$parent.$refs.tinymce)),this.editor.refresh())}),this.$watch("$parent.content",function(t){t!=this.editor.getValue()&&(this.editor.setValue(t),this.editor.refresh())}),this.refreshEditor(i,e),this.$emit("ready")},getHiddenHeight:function(t){var e;return"none"!==i.css(t,"display")?i.height(t):(i.css(t,{position:"absolute",visibility:"hidden",display:"block"}),e=i.height(t),i.removeAttr(t,"style"),e)},refreshEditor:function(t,e){var i=this;if("combine"!=e){var a=n(t,"li");a&&new MutationObserver(function(){"none"!=a.style.display&&i.editor.refresh()}).observe(a,{attributes:!0,childList:!0})}}}}},function(t,e){t.exports={name:"plugin-link",plugin:!0,created:function(){if("undefined"!=typeof tinyMCE){var t=this;this.$parent.editor.plugins.push("-pagekitLink"),tinyMCE.PluginManager.add("pagekitLink",function(e){var i=function(){var i=e.selection.getNode();if("A"===i.nodeName){e.selection.select(i);var a={link:i.attributes.href?i.attributes.href.nodeValue:"",txt:i.innerHTML}}else i=document.createElement("a"),a={};new(Vue.extend(t.$parent.$options.utils["link-picker"]))({parent:t,data:{link:a}}).$mount().$on("select",function(t){i.setAttribute("href","");var a=Object.keys(i.attributes).reduce(function(e,a){var n=i.attributes[a].name;return"data-mce-href"===n?e:e+" "+n+'="'+("href"===n?t.link:i.attributes[a].nodeValue)+'"'},"");e.selection.setContent("<a"+a+">"+t.txt+"</a>"),e.fire("change")})};e.on("click",function(t){"A"==t.target.nodeName&&i()}),e.ui.registry.addToggleButton("link",{tooltip:"Insert/edit link",icon:"link",onAction:function(){i()},onSetup:function(t){return e.selection.selectorChangedWithUnbind("a",t.setActive).unbind}}),e.ui.registry.addMenuItem("link",{context:"insert",icon:"link",text:"Insert/edit link",onAction:function(){i()}})})}}}},function(t,e){t.exports={name:"plugin-image",plugin:!0,created:function(){if("undefined"!=typeof tinyMCE){var t=this;this.$parent.editor.plugins.push("-pagekitImage"),tinyMCE.PluginManager.add("pagekitImage",function(e){var i=function(){var i=e.selection.getNode();if("IMG"!==i.nodeName||i.hasAttribute("data-mce-object"))i=new Image||document.createElement("img"),a={};else{e.selection.select(i);var a={src:i.attributes.src.nodeValue,alt:i.attributes.alt.nodeValue}}new(Vue.extend(t.$parent.$options.utils["image-picker"]))({name:"image-picker",parent:t,data:{image:{data:a}}}).$mount().$on("select",function(t){i.setAttribute("src",""),i.setAttribute("alt","");var a=Object.keys(i.attributes).reduce(function(e,a){var n=i.attributes[a].name;return"data-mce-src"===n?e:e+" "+n+'="'+(t.data[n]||i.attributes[a].nodeValue)+'"'},"");e.selection.setContent("<img"+a+">"),e.fire("change")})};e.ui.registry.addToggleButton("image",{tooltip:"Insert/edit image",icon:"image",onAction:function(){i()},onSetup:function(t){return e.selection.selectorChangedWithUnbind("img:not([data-mce-object],[data-mce-placeholder]),figure.image",function(e){t.setActive(e),e&&i()}).unbind}}),e.ui.registry.addMenuItem("image",{icon:"image",text:"Insert/edit image",context:"insert",onAction:function(){i()}})})}}}},function(t,e){t.exports={name:"plugin-video",plugin:!0,created:function(){if("undefined"!=typeof tinyMCE){var t=this;this.$parent.editor.plugins.push("media"),this.$parent.editor.plugins.push("-pagekitVideo"),tinyMCE.PluginManager.add("pagekitVideo",function(e){var i=function(){var i,a,n={},o=e.selection.getNode(),s={};"IMG"===o.nodeName&&o.hasAttribute("data-mce-object")?(e.selection.select(o),Object.keys(o.attributes).forEach(function(t){var e=o.attributes[t].name;("width"===e||"height"===e||(e=e.match(/data-mce-p-(.*)/))&&(e=e[1]))&&(s[e]=""===o.attributes[t].nodeValue||o.attributes[t].nodeValue)})):"SPAN"===o.nodeName&&o.hasAttribute("data-mce-object")&&(o=o.firstChild)&&(i=(a=(a=o.getAttribute("src")).split("?"))[1],a=a[0],String(i).split("&").forEach(function(t){t=t.split("="),s[t[0]]=t[1]}),s.src=a,s.width=o.getAttribute("width"),s.height=o.getAttribute("height"),Object.keys(o.attributes).forEach(function(t){var e=o.attributes[t].name;"src"!==e&&"width"!==e&&"height"!==e&&(n[e]=o.attributes[t].nodeValue)}));var r=new(Vue.extend(t.$parent.$options.utils["video-picker"]))({parent:t,data:function(){return{video:{data:s}}}}).$mount().$on("select",function(t){var i,a,o;delete t.data.playlist,(o=r.isYoutube)?(a="https://www.youtube.com/embed/"+o[1]+"?",t.data.loop&&(t.data.playlist=o[1])):(o=r.isVimeo)&&(a="https://player.vimeo.com/video/"+o[3]+"?"),a?(Object.keys(t.data).forEach(function(e){"src"!==e&&"width"!==e&&"height"!==e&&(a+=e+"="+(_.isBoolean(t.data[e])?Number(t.data[e]):t.data[e])+"&")}),n.src=a.slice(0,-1),n.width=t.data.width||690,n.height=t.data.height||390,n.allowfullscreen=!0,i="<iframe",Object.keys(n).forEach(function(t){i+=" "+t+(_.isBoolean(n[t])?"":'="'+n[t]+'"')}),i+="></iframe>"):(i="<video",Object.keys(t.data).forEach(function(e){var a=t.data[e];a&&(i+=" "+e+(_.isBoolean(a)?"":'="'+a+'"'))}),i+="></video>"),e.selection.setContent(""),e.insertContent(i),e.fire("change")})};e.ui.registry.addIcon("media",'<svg width="24" height="24"><path d="M4 3h16c.6 0 1 .4 1 1v16c0 .6-.4 1-1 1H4a1 1 0 0 1-1-1V4c0-.6.4-1 1-1zm1 2v14h14V5H5zm4.8 2.6l5.6 4a.5.5 0 0 1 0 .8l-5.6 4A.5.5 0 0 1 9 16V8a.5.5 0 0 1 .8-.4z" fill-rule="nonzero"></path></svg>'),e.ui.registry.addToggleButton("media",{tooltip:"Insert/edit video",icon:"media",onAction:function(){i()},onSetup:function(t){return e.selection.selectorChangedWithUnbind("img[data-mce-object], span[data-mce-object]",function(e){t.setActive(e),e&&i()}).unbind}}),e.ui.registry.addMenuItem("media",{icon:"media",text:"Insert/edit video",context:"insert",onAction:function(){i()}})})}}}},function(t,e,i){"use strict";i.r(e);var a=i(10),n=i(3);for(var o in n)"default"!==o&&function(t){i.d(e,t,function(){return n[t]})}(o);var s=i(0),r=Object(s.a)(n.default,a.a,a.b,!1,null,null,null);e.default=r.exports},function(t,e,i){"use strict";i.r(e);var a=i(11),n=i(5);for(var o in n)"default"!==o&&function(t){i.d(e,t,function(){return n[t]})}(o);var s=i(0),r=Object(s.a)(n.default,a.a,a.b,!1,null,null,null);e.default=r.exports},function(t,e,i){"use strict";i.r(e);var a=i(12),n=i(7);for(var o in n)"default"!==o&&function(t){i.d(e,t,function(){return n[t]})}(o);var s=i(0),r=Object(s.a)(n.default,a.a,a.b,!1,null,null,null);e.default=r.exports}]);