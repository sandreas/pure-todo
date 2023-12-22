window.Storage.prototype.set = function (key, value) {
    localStorage.setItem(key, JSON.stringify(value));
}
window.Storage.prototype.get = function (key) {
    try {
        return JSON.parse(localStorage.getItem(key) || "null");
    } catch (e) {
        console.error(e);
    }
    return null;
}