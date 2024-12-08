# pure-todo
pure-todo is a dead simple todo list application. Its pretty new and unpolished, but these basic features are working:

## Features
- Only one PHP file with some JavaScript and CSS
- REST API available (undocumented ATM)
- User Management with permissions
- Create private and shared lists
- Drag & Drop reordering
- Token-Based login with QR Code support
- Designed for mobile and desktop devices

## Screenshots
<img src="doc/img/01_items.png" alt="Todo items" width="443" height="466" style="border:1px dotted white;margin:15px;"/>

<details>
  <summary style="font-size:2rem;">More screenshots</summary>

<img src="doc/img/02_lists.png" alt="Todo lists" width="443" height="466" style="border:1px dotted white;margin:15px;"/>
<img src="doc/img/03_users.png" alt="Users" width="443" height="466" style="border:1px dotted white;margin:15px;"/>
<img src="doc/img/04_create_list.png" alt="Create list" width="443" height="466" style="border:1px dotted white;margin:15px;"/>
<img src="doc/img/05_create_user.png" alt="Create user" width="443" height="466" style="border:1px dotted white;margin:15px;"/>
<img src="doc/img/06_setup.png" alt="Setup" width="443" height="466" style="border:1px dotted white;margin:15px;"/>

<img src="doc/img/07_qrcode.png" alt="QR Code" width="443" height="466" style="border:1px dotted white;margin:15px;"/>
</details>


## Setup
The setup is pretty simple:


- Put the `public` and `data` on your PHP 8 capable webspace and ensure PHP has write permissions in `data`
- Change the token secret in `index.php` (`$_ENV["TOKEN_SECRET"] ??= "<use-a-strong-token-secret-here>";`)
- Create a (sub-) domain pointing to `public` as main directory
  - depending on your webserver, you need to add a rewrite rule to write every request to `index.php`

## Usage

After pure-todo is running, you can create lists (private or shared), add users and manage your todo items. It's more or less self explanatory. 
However, there are some minor things that are useful to know.

- pure-todo can be installed and used as PWA, so it looks and acts like a native App. Click on the dog icon on the left to use PWA mode
- You can reorder items by holding the move icon in front of every item. If you'd like to give top prio, double tap, for lowest use triple tap
- Deleting users or lists is not implemented yet - mainly because I did not need it. Feel free to submit a PR
  - pure-todo is based on sqlite, so if you would like to change data manually, all it takes is change / delete the data manually the sqlite database
  - to backup pure-todo items, backup the database file and the token