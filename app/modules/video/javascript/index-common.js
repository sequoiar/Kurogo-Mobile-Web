function loadSection(select) {
    window.location = "./index.php?section=" + select.value;
}

function toggleSearch() {
    var categorySwitcher = document.getElementById("category-switcher");
    
    if (categorySwitcher.className == "search-mode") {
        categorySwitcher.className = "category-mode";
    } else {
        categorySwitcher.className = "search-mode";
        document.getElementById("search_terms").focus();
    }
    return false;
}

function submitenter(myfield, e) {
    var keycode;
    if (window.event) {
        keycode = window.event.keyCode;
        
    } else if (e) {
        keycode = e.keyCode;        
        
    } else {
        return true;
    }

    if (keycode == 13) {
       myfield.form.submit();
       return false;
       
    } else {
        return true;        
    }
}
