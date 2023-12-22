# pure-todo
pure-todo is a dead simple todo list application




## todo
- Add filter / search option

## notes
```php
/**
 * Todo
 * - Sort does not work... instead of index, use priority of the item that is replaced
 * - Enum for HttpMethods
 * - simple logging
 * - Error handling
 * - User-Details and Logout button!
 * - QRCode
 */

/*
 * Font generation:
 *
 * - https://sonneiltech.com/2021/02/how-to-create-your-own-custom-icon-font/
 *
 * - icomoon app (https://icomoon.io/app/#/select)
 * - select icons to use
 * - generate font (bottom right)
 * - download font (bottom right)
 * - copy css contents into php file
 * - remove all src() from @font-face
 * - generate base64 from ttf font (pilabor.com/dev)
 * - paste base64 `src: url(data:font/ttf;base64,AAEAAAALAI...AAAAAA) format('truetype');`
 * - use icon: `<i class="icon-drag_indicator"></i>`
 */
```


## Icons / Behaviour
### Todo
- Header
  - filter / search: filter_list_alt, search
  - list dropdown: arrow_drop_down
  - add: add_task, add_circle_outline
- Content Todo
  - order via drag: drag_indicator
  - title: no icon, double tap to edit
  - finish: check_circle, check_circle_outline
  - caption bottom: done_all => mark all as done
- Content done (do I need the headline `Done` or is it enough to strike content and margin)
  - cleanup: delete_sweep
  - uncheck: remove_done

### Lists
- General
  - shared: connect_without_contact
  - private: lock_outline
- Header
  - `Lists` as headline
  - add: post_add
- Content
  - order via drag: drag_indicator
  - title: no icon, double tap to edit
  - edit: create (optional? maybe edit form has a delete button?)
  - delete: delete

### Users
- Header
    - `Users` as headline
    - add: person_add_alt_1
- Content
    - Name: no icon, double tap to edit
    - edit: create (optional? maybe edit form has a delete button?)
    - delete: delete


### Navigation
- todo: check
- list: format_list_bulleted
- user: person
- logout: logout
