
function initApp() {
    loadSettings();
    parseRoute();
    handleLoginLogout();
    showLoadingLayer();
    var message = "";
    get('status').then(state => {
        handleState(state);
    }).catch(function (reason) {
        redirectTo('login');
        message = "could not fetch status page: "+reason;
    }).finally(function() {
        hideLoadingLayer();
        renderApp(message);
    });
}

function handleState(state) {
    if (state["authenticated"] && state['user']) {
        window.authenticatedUser = state['user'];
        updateUserProfile();
        return;
    }

    if(state["setupMode"]) {
        resetSettings();
        redirectTo('setup');
        return;
    }

    redirectTo('login');
}

function updateUserProfile() {
    var navItem = id('navigation-list-users').querySelector('span');
    if(window.authenticatedUser.admin) {
        navItem.innerText = "Users";
        document.querySelectorAll('#users-header i, #users-header a').forEach(e => {
            show(e)
        });
    } else {
        navItem.innerText = "Profile";
        document.querySelectorAll('#users-header i, #users-header a').forEach(e => {
            hide(e)
        });
    }
    document.querySelector('#users-header h1').innerText = navItem.innerText;
}

function redirectTo(entity, selection, identifier) {
    window.route = {
        entity: entity || '',
        selection: selection || '',
        identifier: identifier || ''
    }
}

function changeLocation(entity, selection, identifier) {
    location.href = buildHref(entity, selection, identifier);
}

function defaultSettings() {
    return {
        bearerToken: null,
        selectedListId: null
    };
}
function resetSettings() {
    window.settings = defaultSettings();
    saveSettings();
}
function parseRoute() {
    var parts = location.hash.replace(/^[/#!]+/, '').replace(/[/#!]+$/, '').split('/').filter(p => p.trim() !== "");
    window.route = {
        entity: parts.shift() || "todos",
        selection: parts.shift() || "",
        identifier: parts.shift() || ""
    }
}

function confirmLogout() {
    if(confirm("Proceed to logout?")) {
        changeLocation('logout');
    }
}
function handleLoginLogout() {
    switch(window.route.entity) {
        case "logout":
            window.settings.bearerToken = null;
            window.authenticatedUser = null;
            saveSettings();
            location.hash = '#/!';
            break;
        case "login":
            window.settings.bearerToken = window.route.selection;
            saveSettings();
            location.hash = '#/!';
            break;
    }
}

function renderApp(message) {
    switch(window.route.entity) {
        case "setup":
            renderUserForm(true);
            break;
        case "login":
            renderLoginForm(message);
            break;
        default:
            renderRoute();
            break;
    }
}


function renderRoute() {
    activateNav();
    switch(route.entity) {
        case 'lists':
            renderLists();
            break;
        case 'users':
            renderUsers();
            break;
        default:
            renderTodos();
            break;
    }
}

function activateNav() {
    Array.from(document.querySelectorAll('#navigation-list li')).forEach(e => {
        if(e.id === 'navigation-list-'+route.entity) {
            addClass(e, 'active');
        } else {
            removeClass(e, 'active');
        }
    });
}
function renderTodos() {
    prepareRouteRender();

    if(route.selection === "new") {
        renderItemForm();
        return;
    }

    if(route.selection.match(/^[1-9][0-9]*$/)) {
        get('items/'+route.selection).then((item) => {
            renderItemForm(item);
        });
        return;
    }

    loadLists().then(handleListsLoadedForItemsRoute);

}

function renderLists() {
    prepareRouteRender();

    if(route.selection === "new") {
        renderListForm();
    } else if (+route.selection > 0) {
        get('lists/'+route.selection).then((list) => {
            renderListForm(list);
        });
    } else {
        loadLists().then(renderListsTable);
    }
}

function renderUsers() {
    prepareRouteRender();
    if(!window.authenticatedUser.admin) {
        renderProfile();
    } else if(route.selection === "new") {
        renderUserForm();
    } else if (+route.selection > 0) {
        get('users/'+route.selection).then((user) => {
            renderUserForm(false, user);
        });
    } else {
        loadUsers().then(renderUsersTable);
    }
}

function renderProfile() {
    var qrCodeIcon = buildQrCodeIcon(window.authenticatedUser);

    var table = html('table', {class: 'index-table'},
        html('tbody', null,
            buildKeyValueRow("Username", text(window.authenticatedUser.username)),
            buildKeyValueRow("Name", text(window.authenticatedUser.name)),
            // buildKeyValueRow("Admin", text(window.authenticatedUser.admin ? 'yes' : 'no')),
            buildKeyValueRow("Access", qrCodeIcon),

        ))

    replaceIdContent('users-content', table);
}

function buildKeyValueRow(key, value) {
    return html('tr', null,
        html('td', {class: 'key'}, text(key)),
        html('td', {class: 'value'}, value)
    );
}

function buildQrCodeIcon(u) {
    var qrCodeIcon = renderButton("btn-qrcode", 'qr_code_2'); // html("i", {class: 'icon-qr_code_2'});
    qrCodeIcon.addEventListener('click', () => {
        showQrCode(u);
    });
    return qrCodeIcon;
}
function showQrCode(u) {
    var url = location.href.substr(0, location.href.indexOf('#!/users')) + '#!/login/'+u.token;

    var maxSize = Math.floor(Math.max(getWidth(), getHeight()) / 3);


    var qr = html('div', {class:"qr-bg"});
    var clipText = 'Copy Token to clipboard';
    var clipUrlText = 'Copy Login-URL to clipboard';
    var clip = html('button', {type:'button'}, text(clipText));
    var clipUrl = html('button', {type:'button'}, text(clipUrlText));
    var modal = html('div', {class:'qr-container modal center'},qr, clip,clipUrl);

    try {
        var matrix = QrCode.generate(url);
        var uri = QrCode.render('svg-uri', matrix);
        qr.appendChild(html('img', {src:uri,alt:"qrcode",width:maxSize,height:maxSize}));
    } catch(e) {
        debugger;
        var errorDiv = html('div', {class:"center"}, text('Barcode could not be created, please use the url instead'));
        errorDiv.style.width = maxSize;
        errorDiv.style.height = maxSize;
        qr.appendChild(errorDiv);
    }

    document.body.appendChild(modal);
    modal.addEventListener('click', () => {
        document.body.removeChild(modal);
    });
    var keyDownListener = function() {
        document.body.removeChild(modal);
        document.removeEventListener('keydown', keyDownListener);
    }
    document.addEventListener('keydown', keyDownListener);

    clip.addEventListener('click', (e) => {
        e.stopPropagation();
        Clipboard.copy(u.token);
        clip.innerText = 'copied!';
        window.setTimeout(function() {
            clip.innerText = clipText;
        }, 1000);
    });

    clipUrl.addEventListener('click', (e) => {
        e.stopPropagation();
        Clipboard.copy(url);
        clipUrl.innerText = 'copied!';
        window.setTimeout(function() {
            clipUrl.innerText = clipUrlText;
        }, 1000);
    });

    /*
    qrcode.clear(); // clear the code.
    qrcode.makeCode("url");
     */

}

function renderListsTable() {
    setClass(id('lists-content'), 'center', items.length === 0)

    if(lists.length === 0) {
        replaceIdContent('lists-content',
            html('div', null,
                html('span', null,
                    text('No lists defined. To add a list, use  '),
                    html('i', {class:'icon-post_add'})
                )
            )
        );
    } else {

        /*
        document.getElementById('cleanup').addEventListener('click', () => {
            if(!confirm("Delete all finished items? Cannot be undone.")) {
                return;
            }
            httpDelete('items?where[listId]='+window.settings.selectedListId + '&where[finished]=1')
                .then(initApp);
        });
        */

        var tbody = html('tbody');
        var listRows = lists.map(l => {
            var deleteIcon = renderButton('btn-delete', 'delete'); // html("i", {class: 'icon-delete'});
            deleteIcon.addEventListener('click', () => {
                if(!confirm("Delete list including items? Cannot be undone.")) {
                    return;
                }
                httpDelete('lists/'+l.id)
                    .then(initApp);
            })

            var td = html('td', {class: "first drag"},
                // renderButton('', 'drag_indicator')
            );
            var tdTitle = html('td', {class: "second title"}, buildListName(l));
            var tdDelete = html('td', {class: "third check"},deleteIcon);
            return html('tr',
                {class: "lists-table-tr", 'data-item-id': l.id},
                td,
                tdTitle,
                tdDelete
            );
        });
        listRows.forEach(tr => tbody.appendChild(tr));

        replaceIdContent('lists-content', html('table', {id:'lists-table', class:'index-table'}, tbody));
        // registerDragDropEventHandlers();
    }
}

function renderUsersTable() {
    setClass(id('users-content'), 'center', items.length === 0)

    if(users.length === 0) {
        replaceIdContent('users-content',
            html('div', null,
                html('span', null,
                    text('No users defined. To add a user, use  '),
                    html('i', {class:'icon-person_add_alt_1'})
                )
            )
        );
    } else {
        var tbody = html('tbody');
        var userRows = users.map(u => {
            var deleteIcon = window.authenticatedUser.id === u.id ? null : renderButton('btn-delete', 'delete'); //html("i", {class: 'icon-delete'});
            if(deleteIcon !== null) {
                deleteIcon.addEventListener('click', () => {
                    if(!confirm("Delete user including items? Cannot be undone.")) {
                        return;
                    }
                    httpDelete('users/'+u.id)
                        .then(initApp);
                });
            }


            var td = html('td', {class: "first"}, buildQrCodeIcon(u));
            var tdTitle = html('td', {class: "second title"}, buildUserName(u) );
            var tdDelete = html('td', {class: "third check"},deleteIcon);
            return html('tr',
                {class: "users-table-tr", 'data-item-id': u.id},
                td,
                tdTitle,
                tdDelete
            );
        });
        userRows.forEach(tr => tbody.appendChild(tr));

        replaceIdContent('users-content', html('table', {id:'users-table', class:'index-table'}, tbody));
        // registerDragDropEventHandlers();
    }
}

function buildUserName(u) {
    return html('span', {class:'icon-with-text'},

        /*html('i', {class:'icon-' + (u.admin ? 'supervised_user_circle' : 'person')}),*/
        text(u.name + " (" + u.username+"" + ", " + (u.admin ? 'admin' : 'user') + ')'));
}

function buildListName(l) {
    return html('span', {class:'icon-with-text'},
        html('i', {class:'icon-' + (l.shared ? 'connect_without_contact' : 'lock_outline')}),
        text(l.name));
}

function prepareRouteRender() {
    hideAllRoutes();
    show(id('navigation'));
    show(id(route.entity));
}


function loadLists() {
    return get('lists').then(json => window.lists = json);
}

function loadUsers() {
    return get('users').then(json => window.users = json);
}


function handleListsLoadedForItemsRoute() {
    if(lists.length === 0) {
        renderListForm();
    } else {
        var routeListId = 0;
        if(route.entity === "todos" && route.selection !== "" && route.identifier !== "") {
            routeListId = +route.identifier;
        }

        if(getListById(routeListId) !== null && routeListId !== window.settings.selectedListId) {
            window.settings.selectedListId = routeListId;
            saveSettings();
        }
        if(!window.settings.selectedListId) {
            window.settings.selectedListId = +window.lists[0].id
            saveSettings();
        }
        renderListSelection();
        loadAndRenderItems();
    }
}

function renderListForm(list) {
    prepareFormRender();
    list = list || {};
    var isFirstList = window.lists.length === 0;
    var listId = list["id"] || null;
    var createMode = listId === null;
    var description = "Create todo list";
    if(listId) {
        description = "Edit todolist";
    }
    if(isFirstList) {
        description = "Create your first list to add todo items";
    }
    var formConfig = {
        id: 'list',
        description: description,
        elements: [
            createMode ? null : elementConfig('hidden', 'id', null, listId),
            elementConfig('text', 'name', 'Name', list["name"] || '', listId),
            elementConfig('checkbox', 'shared', 'Share with other users', list["shared"] || ''),
            isFirstList ? null : elementConfig('button', 'cancel', 'Cancel'),
            elementConfig('submit', 'save', 'Save'),
        ]
    }

    var listForm = createForm(formConfig);
    listForm.onsubmit = function() {
        var formData = serializeForm(document.querySelector('form'));
        var result = createMode
            ? post('lists', formData)
            : patch('lists/'+listId, formData);

        result.then((response) => {
            if(isFirstList) {
                changeLocation('todos', 'lists', response.id);
            } else {
                changeLocation('lists');
            }
        });
        return false;
    };
    var cancelButton = listForm.querySelector('#cancel');
        if(cancelButton) {
        cancelButton.addEventListener('click',function() {
            changeLocation('lists');
        });
    }
    var content = html('div', null,
        html("h1", null, text("List")),
        listForm
    );

    replaceIdContent('form-container', content);
    focusFirstFormElement();
}

function renderListSelection() {
    // only show dropdown when lists > 1
    var ul = lists.length === 1 ? null : html('ul', {class:"dd-menu"});
    var input = html('input', {type:'checkbox', class:'dd-input'});
    var classes = lists.length === 1 ? 'dd-button single-item' : 'dd-button multiple-items';

    var teaser = html('div', {id:'list-teaser', class:classes});
    var label = html('label', {class:'dropdown'},
        teaser,
        input,
        ul
    );

    var selectedItems = lists.filter(l => l.id === window.settings.selectedListId);
    if(selectedItems.length > 0) {
        teaser.appendChild(buildListName(selectedItems[0]));
    }

    var privateLists = lists.filter(l => !l.shared && selectedItems.indexOf(l) === -1);
    var sharedLists = lists.filter(l => l.shared && selectedItems.indexOf(l) === -1);

    if(privateLists.length > 0) {
        ul.appendChild(html('li', {class: 'optgroup'}, text('private')));
        privateLists.map(l => html('li', {class:'private'}, buildListSelectionLink(l)))
            .forEach(li => ul.appendChild(li));
    }

    if(sharedLists.length > 0) {
        ul.appendChild(html('li', {class: 'optgroup'}, text('shared')));
        sharedLists.map(l => html('li', {class:'shared'}, buildListSelectionLink(l)))
            .forEach(li => ul.appendChild(li));
    }


    // ul.appendChild(html('li', {class:"divider"}));
    replaceIdContent('todos-header-lists-selection', label);

    /*
    // only show dropdown when lists > 1
    var ul = lists.length === 1 ? null : html('ul', {class:"dd-menu"});
    var classes = lists.length === 1 ? 'dropdown single-item' : 'dropdown multiple-items';
    var input = html('input', {type:'checkbox', id:'dd-input', name:'dd-input', class:'dd-input'});


    // var teaser = html('div', {id:'list-teaser', class:classes});
    var label = html('label', {class:classes, for:'dd-input'});

    var selectedItems = lists.filter(l => l.id === window.settings.selectedListId);
    if(selectedItems.length > 0) {
        label.appendChild(buildListName(selectedItems[0]));
    }

    var sharedLists = lists.filter(l => l.shared && selectedItems.indexOf(l) === -1);

    if(privateLists.length > 0) {
        ul.appendChild(html('li', {class:"dd-menu-header"}), text('Private'));
        privateLists.map(l => html('li', {class:'private'}, buildListSelectionLink(l)))
            .forEach(li => ul.appendChild(li));
    }

    if(sharedLists.length > 0) {
        ul.appendChild(html('li', {class:"dd-menu-header"}), text('Shared'));
        sharedLists.map(l => html('li', {class:'shared'}, buildListSelectionLink(l)))
            .forEach(li => ul.appendChild(li));
    }

    var container = html('div', null, label, input, ul);




    // ul.appendChild(html('li', {class:"divider"}));
    replaceIdContent('todos-header-lists-selection', container);

     */

}

function buildListSelectionLink(listItem) {
    return html('a', {href: buildHref('todos', 'list', listItem.id)}, buildListName(listItem));
}

function buildHref(entity, selection, identifier) {
    var href = '/#!/'+entity;
    if(arguments.length > 1 && selection !== null && selection !== undefined && selection !== "") {
        href += '/'+selection;
    }
    if(arguments.length > 2 && identifier !== null && identifier !== undefined && identifier !== "") {
        href += '/'+identifier;
    }
    return href;
}


function loadAndRenderItems() {
    loadItems().then(renderItemTables);
}

function loadItems() {
    var urlPart = "items";
    if(window.settings.selectedListId) {
        urlPart += '?where[listId]='+window.settings.selectedListId;
    }
    return get(urlPart).then(json => {
        window.items = json;
    });
}



function renderItemTables() {
    setClass(id('todos-content'), 'center', items.length === 0)
    if(items.length === 0) {
        replaceIdContent('todos-content',
            html('div', null,
                html('span', null,
                    text('List is empty. To add items, use  '),
                    html('i', {class:'icon-add_task'})
                )
            )
        );
    } else {
        var searchTerm = id('todos-filter-query').value.toLowerCase();
        var allItems = window.items.filter( i => i.title.toLowerCase().indexOf(searchTerm) !== -1);

        var openItems = allItems.filter(i => !i.finished);
        var open = renderOpenItems(openItems);
        var openContainer = html('div', {class: 'open-items-container'}, open);

        var finishedItems = allItems.filter(i => i.finished);
        var cleanupIcon =  renderButton('cleanup', 'delete_sweep'); // html("i", {class: 'icon-delete_sweep pointer', id: 'cleanup'});
        var finishedHead =
            html('div', {class: "done-head"},
                cleanupIcon,
                html('h1', null, text('Done'))
            );
        var finished = renderFinishedItems(finishedItems);
        var finishedContainer = html('div', {class: 'done-items-container'}, finishedHead, finished);

        changeVisibility(finishedContainer, finishedItems.length === 0);
        replaceIdContent('todos-content', html('div', null, openContainer, finishedContainer));


        registerDragDropEventHandlers();
        registerBeginEditEventHandlers();
        registerToggleEventHandlers();
        registerCleanupEventHandlers();
    }



}

function renderOpenItems(items) {
    var tbody = html('tbody');
    items.forEach(function (i) {
        tbody.appendChild(renderOpenItemRow(i));
    });
    return html('table', {id: 'items-open', class:'index-table'}, tbody);
}

function renderFinishedItems(items) {
    var tbody = html('tbody');
    items.forEach(function (i) {
        tbody.appendChild(renderFinishedItemRow(i));
    });
    return html('table', {id: 'items-done', class:'index-table'}, tbody);
}

function renderOpenItemRow(i) {
    // var td = html('td', {class: "first drag"}, html("i", {class: 'icon-drag_indicator pointer'}));
    var td = html('td', {class: "first drag"},
        renderButton('drag-button', 'drag_indicator')
        );

    var tdTitle = html('td', {class: "second title"}, text(i.title));
    var tdFinish = html('td', {class: "third check"},
        renderButton('toggle-done', 'check_circle')
    );
    return html('tr',
        {class: "items-open-tr", 'data-item-id': i.id},
        td,
        tdTitle,
        tdFinish
    );
}

function renderButton(btnClass, icon) {
    return html('button', {type:'button',class:btnClass + " icon-btn"}, html("i", {class: 'icon-'+icon}))
}

function renderFinishedItemRow(i) {
    var deleteIcon = renderButton('remove', 'delete');// html("i", {class: 'remove icon-delete pointer'});
    deleteIcon.addEventListener('click', () => {
        if(!confirm("Delete item '" + i.title + "'? Cannot be undone.")) {
            return;
        }
        httpDelete('items/'+i.id)
            .then(initApp);
    });
    return html('tr',
        {class: "items-done-tr", 'data-item-id': i.id},
        html('td',{class: "first remove"},
            deleteIcon
        ), // unmark icon
        html('td', {class: "second title"}, text(i.title)),
        html('td', {class: "third uncheck"},
            // html("i", {class: 'toggle-done icon-remove_done pointer'})
            renderButton('toggle-done', 'remove_done')
        )
    );
}



function registerDragDropEventHandlers() {
    document.querySelectorAll('#items-open td.drag').forEach(td => {
        var tr = td.closest('tr');
        var itemId = extractItemId(td);
        var onDropWrapper = function (e) {
            onDrop(e, itemId);
        };

        // todo: fix ios: https://stackoverflow.com/questions/6600950/native-html5-drag-and-drop-in-mobile-safari-ipad-ipod-iphone
        td.setAttribute('draggable', true);
        td.addEventListener('dragstart', onDragStart);
        tr.addEventListener('dragover', onDragOver);
        tr.addEventListener('dragleave', onDragLeave);
        td.addEventListener('drop', onDropWrapper);

        var timeout;

        var button = td.querySelector('button');
        var clicks = 0;
        var handleClicks = function(e, clickCount) {
            clicks = 0;
            if(timeout) {
                window.clearTimeout(timeout);
            }
            var newIndex = -1;
            if(clickCount === 2) {
                newIndex = 0;
            } else if(clickCount === 3) {
                newIndex = Number.MAX_SAFE_INTEGER;
            }
            if(newIndex === -1) {
                return;
            }

            var newPrio = moveRowIndexPriority(e.target.closest('tr'), newIndex);
            // var item = getItemById(itemId);
            // if(item.priority !== newPrio) {
                patchItem(itemId, {priority: newPrio}).then(initApp);
            // }
        };
        if(button) {
            button.addEventListener('click', function(e) {
                if(timeout) {
                    window.clearTimeout(timeout);
                }

                clicks++;
                timeout = window.setTimeout(function() {
                    handleClicks(e, clicks);
                }, 350);
            });
        }

    });
}

function onDragStart(e) {
    var tr = e.target.closest("tr");
    window.dragStartRow = tr;

    var text = tr.querySelector('.title').innerText;
    e.stopPropagation();
    e.dataTransfer.dropEffect = 'move';
    e.dataTransfer.setData('text/plain', text);
}

function onDragOver(e) {
    window.dragStartRow.setAttribute('DragOver', true);
    e.stopPropagation();
    e.preventDefault();
    e.dataTransfer.dropEffect = 'copy';

    if (!window.dragStartRow) {
        return;
    }

    var dragOverTr = e.target.closest('tr');
    var tbody = e.target.closest("tbody");
    var rows = Array.from(tbody.querySelectorAll('tr'));
    if (rows.indexOf(dragOverTr) > rows.indexOf(window.dragStartRow)) {
        dragOverTr.after(window.dragStartRow);
    } else {
        dragOverTr.before(window.dragStartRow);
    }
}

function onDragLeave(e) {
    window.dragStartRow.removeAttribute('DragOver');
    e.stopPropagation()
    e.preventDefault();
}

function onDrop(e, itemId) {

    window.dragStartRow.removeAttribute('DragOver');
    e.stopPropagation()
    e.preventDefault();

    var droppedTr = e.target.closest('tr');
    var droppedTbody = e.target.closest('tbody');
    var allTrs = Array.from(droppedTbody.querySelectorAll('tr'));
    var newIndex = allTrs.indexOf(droppedTr);
    var newPriority = allTrs.length - newIndex;

    patchItem(itemId, {priority: newPriority});
}



function moveRowIndexPriority(tr, newIndex) {
    var tbody = tr.closest('tbody');
    var allTrs = Array.from(tbody.querySelectorAll('tr'));

    if(allTrs.length === 0) {
        return;
    }

    if(allTrs.length <= newIndex) {
        newIndex = allTrs.length;
    }

    if(newIndex === allTrs.length) {
        allTrs[newIndex-1].after(tr);
    } else {
        allTrs[newIndex].before(tr);
    }
    return allTrs.length - newIndex;
}

function registerBeginEditEventHandlers() {
    Array.from(document.querySelectorAll('#items-open td.title')).forEach(td => {
        td.addEventListener('click', function () {
            if (td.getAttribute('contenteditable')) {
                return;
            }

            td.setAttribute('contenteditable', true)
            // timeout is required for this to work
            setTimeout(function () {
                td.focus();
            }, 0);
        });

        td.addEventListener('focusout', function () {
            finishEditing(td);
        });

        td.addEventListener("keypress", function (event) {
            if (event.key === "Enter" && td.getAttribute('contenteditable')) {
                event.preventDefault();
                td.dispatchEvent(new CustomEvent('focusout'));
            }
        })
    });
}

function finishEditing(tdTitle) {
    if (!tdTitle.getAttribute('contenteditable')) {
        return;
    }
    tdTitle.removeAttribute('contenteditable');
    tdTitle.innerText = tdTitle.innerText.replace(/\r\n|\r|\n/g, ' ').trim();
    patchItem(extractItemId(tdTitle), {
        title: tdTitle.innerText
    });
}


function registerToggleEventHandlers() {
    Array.from(document.querySelectorAll('.toggle-done')).forEach((el) => {
        var itemId = extractItemId(el);
        var item = getItemById(itemId);
        el.addEventListener('click', function () {
            if (item) {
                toggleItemDone(item);
            }
        })
    });
}

function elementConfig(type, id, label, value, properties) {
    var config = {};
    for(var i=0;i<6;i++) {
        if(arguments[i] === null) {
            continue;
        }

        switch(i) {
            case 0:
                config.type = type;
                break;
            case 1:
                config.id = id;
                break;
            case 2:
                config.label = label;
                break;
            case 4:
                config.value = value;
                break;
            case 5:
                config.properties = properties;
                break;
        }
    }
    return config;
}

function renderItemForm(item) {
    prepareFormRender();
    item = item || {};
    var itemId = item['id'] || null;
    var createMode = itemId === null;
    var createPriority = 0;
    if(createMode) {
        items.forEach(i => {
            createPriority = Math.max(createPriority, i.priority) + 1;
        })
    }
    var formConfig = {
        id: 'item',
        elements: [
            elementConfig('hidden', 'id', null, itemId),
            createMode ? elementConfig('hidden', 'priority', null, createPriority): null,
            elementConfig('hidden', 'listId', null, window.settings.selectedListId),
            elementConfig('text', 'title', 'Title', item["title"] || ''),
            elementConfig('button', 'cancel', 'Cancel'),
            elementConfig('submit', 'save', 'Save'),
        ]
    };
    var itemForm = createForm(formConfig);
    itemForm.onsubmit = function() {
        var formData = serializeForm(document.querySelector('form'));
        var result = createMode
            ? post('items', formData)
            : patch('items/'+itemId, formData);
        result.then((/* response */) => {
            changeLocation('todos');
        });
        return false;
    };
    itemForm.querySelector('#cancel').addEventListener('click',function() {
        changeLocation('todos');
    });

    var content = html('div', null,
        html("h1", null, text("Item")),
        itemForm
    );
    // itemForm.onsubmit = submitLoginForm;
    replaceIdContent('form-container', content);
    focusFirstFormElement();
}

function registerCleanupEventHandlers() {
    document.querySelector('.cleanup').addEventListener('click', () => {
        if(!confirm("Delete all finished items? Cannot be undone.")) {
            return;
        }
        httpDelete('items?where[listId]='+window.settings.selectedListId + '&where[finished]=1')
            .then(initApp);
    });
}

function getItemById(itemId) {
    var matches = window.items.filter(i => i.id === itemId);
    if (matches.length > 0) {
        return matches[0];
    }
    return null;
}

function getListById(listId) {
    var matches = window.lists.filter(i => i.id === listId);
    if (matches.length > 0) {
        return matches[0];
    }
    return null;
}


function toggleItemDone(item) {
    patchItem(item.id, {
        finished: !item.finished
    }).then(function () {
        item.finished = !item.finished;
        renderItemTables();
    });
}



function prepareFormRender() {
    hide(id('navigation'));
    hideAllRoutes();
    show(id('form-container'));
}

function hideAllRoutes() {
    Array.from(document.querySelectorAll('#main>div')).forEach((div) => {
        hide(id(div.id));
    });
}

function renderLoginForm(errorMessage) {
    prepareFormRender();
    if(arguments.length === 0) {
        errorMessage = window.settings.bearerToken ? "Invalid bearer token specified" : "";
    }
    var formConfig = {
        id: 'login',
        description: "",
        elements: [
            elementConfig('password', 'token', 'Token'),
            elementConfig('submit', 'login', 'Login'),
        ]
    }

    var loginForm = createForm(formConfig);
    loginForm.onsubmit = function() {
        settings.bearerToken = document.getElementById('token').value;
        window.route.entity = 'todos';
        saveSettings();
        initApp();
        return false;
    };
    replaceIdContent('form-container', html("div", {id: "login-form-container"},
        html("h1", null, text("Authentication")),
        html("p", {class: "error-message"}, text(errorMessage)),
        loginForm
    ));
    focusFirstFormElement();
}

function renderUserForm(setupMode, user) {
    prepareFormRender();
    var adminType = setupMode ? 'hidden' : 'checkbox';
    var idElement = user ? elementConfig('hidden', 'id', null) : null;
    var userId = user ? user.id : null;
    var createMode = setupMode || !user;

    var head = setupMode ? "Setup" : user ? "Edit user":"Create user";
    var desc = setupMode ?"Specify an administrative user to setup pure-todo": "";
    var buttonText = setupMode ? "Setup pure-todo »" : "Save";

    var formConfig = {
        id: 'setup',
        description: desc,
        elements: [
            idElement,
            elementConfig('text', 'username', 'Username'),
            elementConfig('text', 'name', 'Name'),
            elementConfig(adminType, 'admin', 'Admin', "1"),
            setupMode ? null : elementConfig('button', 'cancel', 'Cancel'),
            elementConfig('submit', 'save', buttonText)
        ]
    }

    var setupForm = createForm(formConfig);
    setupForm.onsubmit = function() {
        var formData = serializeForm(document.querySelector('form'));
        var result = createMode
            ? post('users', formData)
            : patch('users/'+userId, formData);


        result.then((response) => {
            if(setupMode) {
                settings.bearerToken = response["token"] || null;
                saveSettings();
                initApp();
            } else {
                changeLocation('users');
            }
        });
        return false;
    };
    var cancelButton = setupForm.querySelector('#cancel');
    if(cancelButton) {
        cancelButton.addEventListener('click',function() {
            changeLocation('users');
        });
    }


    replaceIdContent('form-container', html("div", {id: "setup-form-container"},
        html("h1", null, text(head)),
        setupForm
    ));
    focusFirstFormElement();
}



/**
 * HELPER FUNCTIONS
 */
function extractItemId(node) {
    if (!node) {
        return "";
    }
    var tr = node.closest('tr');
    return +(tr ? tr.getAttribute('data-item-id') || 0 : 0);
}



function replaceIdContent(id, child) {
    console.log("replaceIdContent", id, child);
    replaceContent(document.getElementById(id), child);
}

function replaceContent(el, child) {
    el.innerHTML = '';
    el.appendChild(child);
}
function patchItem(itemId, data) {
    return patch("items/" + itemId, data);
}

