function loadSettings() {
    window.settings = localStorage.get("settings") || window.settings;
}

function saveSettings() {
    localStorage.set("settings", window.settings);
}

function showLoadingLayer() {
    show(id('loading-layer'));
}

function hideLoadingLayer() {
    hide(id('loading-layer'));
}

function showContent(setupMode) {
    if(window.authenticatedUser) {
        show(id(window.route.entity));
        show(id('navigation'));
        var activeItem = id('navigation-list-'+window.route.entity);
        Array.from(document.querySelectorAll('#navigation-list li')).forEach(li =>    removeClass(li, 'active'));
        if(activeItem) {
            addClass(activeItem, 'active');
        }
    } else if(!setupMode) {
        show(id('login'));
    }
}

function hideContent() {
    hide(id('navigation'));
    ['todos', 'lists', 'users', 'login'].forEach(elId => hide(id(elId)));
}
function changeVisibility(t, shouldHide) {
    shouldHide ? hide(t) : show(t);
}
function hide(t) {
    addClass(t, 'hidden');
}

function show(t) {
    removeClass(t, 'hidden');
}

function addClass(t, className) {
    setClass(t, className, true);
}

function removeClass(t, className) {
    setClass(t, className, false);
}

function setClass(t, className, shouldHaveClass) {
    if(!t) {
        return;
    }
    if(shouldHaveClass && !t.classList.contains(className)) {
        t.classList.add(className);
    } else if(!shouldHaveClass && t.classList.contains(className)) {
        t.classList.remove(className);
    }
}

function createForm(formConfig) {
    var id = formConfig.id;
    var form = html('form', {id: id, class: id + '-form'});
    if(formConfig.description) {
        form.appendChild(html('p', {class: "description"}, text(formConfig.description)))
    }
    formConfig.elements.forEach((element) => {
        if(element !== null) {
            form.appendChild(createFormElement(element));
        }
    });
    return form;
}

function createFormElement(element) {
    try {
    var type = element['type'] || null;
    var value = element['value'] || null;
    var label = element['label'] || '';
    var properties = element["properties"] || {};
    // console.log(properties);
    if(type !== null) {
        properties['type'] = type;
    }

    if(value !== null) {
        properties["value"] = value;
    }

    properties['id'] = properties['id'] || element.id;
    properties['name'] = properties['name'] || properties['id'];

    switch(type) {
        case "submit":
            return html('button', properties, text(label));
        case "button":
            return html('button', properties, text(label));
        case "hidden":
            return html("input", properties);
        case "checkbox":
            properties["value"] = '1';
        // fallthrough is intended
        case "text":
        case "password":
            var labelNode = html("label", {for: element.id}, text(label))
            var inputNode = html("input", properties);
            // console.log(element.id, properties);
            return html("div", {class: "form-group form-group-" + type},
                type === "checkbox" ? inputNode : labelNode,
                type === "checkbox" ? labelNode : inputNode
            );
    }
    } catch(e) {
        debugger;
    }
    return null;
}

function serializeForm(formElement) {
    var object = {};
    var formData = new FormData(formElement);
    formData.forEach(function(value, key){
        object[key] = value;
    });
    return object;
}


function html(tag, attributes) {
    var el = document.createElement(tag);

    if (attributes !== null) {
        for (var key in attributes) {
            el.setAttribute(key, attributes[key]);
        }
    }

    for (var i = 2; i < arguments.length; i++) {
        if (arguments[i] !== null) {
            el.appendChild(arguments[i]);
        }
    }
    return el;
}

function text(text) {
    return document.createTextNode(text);
}

function id(id) {
    return document.getElementById(id);
}


function getDefaultHeaders() {
    return window.settings.bearerToken ? {Authorization: 'Bearer ' + window.settings.bearerToken} : {};
}

function get(url) {
    return fetchJson(url, 'GET');
}

function post(url, model) {
    return fetchJson(url, 'POST', model);
}

function patch(url, model) {
    return fetchJson(url, 'PATCH', model);
}

function httpDelete(url) {
    return fetchJson(url, 'DELETE', null);
}

function fetchJson(url, method, body) {
    showLoadingLayer();
    return fetch(url, {
        headers: getDefaultHeaders(),
        method: method,
        body: body === null ? null : JSON.stringify(body)
    }).then(resp => {
        return resp.text();
    }).then(text => {
        try {
            return text === "" ? null : JSON.parse(text);
        } catch(e) {
            // ignore
        }
        return null;
    }).finally(() => {
        hideLoadingLayer();
    });
}

function getWidth() {
    return Math.max(
        document.body.scrollWidth,
        document.documentElement.scrollWidth,
        document.body.offsetWidth,
        document.documentElement.offsetWidth,
        document.documentElement.clientWidth
    );
}

function getHeight() {
    return Math.max(
        document.body.scrollHeight,
        document.documentElement.scrollHeight,
        document.body.offsetHeight,
        document.documentElement.offsetHeight,
        document.documentElement.clientHeight
    );
}

function focusFirstFormElement() {
    var firstElement = document.querySelector('input[type=text],textarea');
    if(firstElement) {
        firstElement.focus();
    }
}

window.Clipboard = (function(window, document, navigator) {
    var textArea,
        copy;

    function isOS() {
        return navigator.userAgent.match(/ipad|iphone/i);
    }

    function createTextArea(text) {
        textArea = document.createElement('textArea');
        textArea.value = text;
        document.body.appendChild(textArea);
    }

    function selectText() {
        var range,
            selection;

        if (isOS()) {
            range = document.createRange();
            range.selectNodeContents(textArea);
            selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
            textArea.setSelectionRange(0, 999999);
        } else {
            textArea.select();
        }
    }

    function copyToClipboard() {
        document.execCommand('copy');
        document.body.removeChild(textArea);
    }

    copy = function(text) {
        createTextArea(text);
        selectText();
        copyToClipboard();
    };

    return {
        copy: copy
    };
})(window, document, navigator);

// How to use
