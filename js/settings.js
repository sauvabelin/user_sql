(function () {
    "use strict";

    var app_id = "user_sql";

    var postForm = function (form, path, extraParams) {
        var body = new URLSearchParams(new FormData(form));
        if (extraParams) {
            Object.entries(extraParams).forEach(function (entry) {
                body.set(entry[0], entry[1]);
            });
        }
        return fetch(OC.generateUrl(path), {
            method: "POST",
            headers: {
                requesttoken: OC.requestToken,
                "Content-Type": "application/x-www-form-urlencoded"
            },
            body: body
        }).then(function (response) {
            return response.json();
        });
    };

    var debounce = function (fn, wait) {
        var timer = null;
        return function () {
            window.clearTimeout(timer);
            timer = window.setTimeout(fn, wait);
        };
    };

    var setVisible = function (element, visible) {
        element.style.display = visible ? "" : "none";
    };

    var adminSettingsUI = function (form) {
        var msg = document.getElementById("user_sql-msg");
        var msg_body = document.getElementById("user_sql-msg-body");
        var hideTimer = null;

        var showMessage = function (state, text) {
            msg.classList.remove("error", "success", "waiting");
            msg.classList.add(state);
            msg_body.textContent = text;
            setVisible(msg, true);
        };

        var click = function (path) {
            window.clearTimeout(hideTimer);
            showMessage("waiting", t(app_id, "Waiting..."));

            postForm(form, path).then(function (data) {
                showMessage(data.status === "success" ? "success" : "error", data.data.message);
            }).catch(function () {
                showMessage("error", t(app_id, "Request failed. Please reload the page and try again."));
            }).then(function () {
                hideTimer = window.setTimeout(function () {
                    setVisible(msg, false);
                }, 10000);
            });
        };

        var autocomplete = function (ids, path) {
            document.querySelectorAll(ids).forEach(function (input) {
                var list = document.createElement("datalist");
                list.id = input.id + "-options";
                input.parentNode.appendChild(list);
                input.setAttribute("list", list.id);

                var refresh = function () {
                    postForm(form, path, {input: input.value}).then(function (items) {
                        list.replaceChildren();
                        (items || []).forEach(function (item) {
                            var option = document.createElement("option");
                            option.value = item;
                            list.appendChild(option);
                        });
                    }).catch(function () {
                    });
                };

                input.addEventListener("focus", refresh);
                input.addEventListener("input", debounce(refresh, 200));
            });
        };

        var cryptoParams = function () {
            var cryptoClass = document.getElementById("opt-crypto_class");
            var content = document.getElementById("opt-crypto_params_content");
            var loading = document.getElementById("opt-crypto_params_loading");

            var cryptoChanged = function () {
                setVisible(content, false);
                setVisible(loading, true);

                var url = OC.generateUrl("/apps/user_sql/settings/crypto/params")
                    + "?" + new URLSearchParams({cryptoClass: cryptoClass.value});

                fetch(url, {headers: {requesttoken: OC.requestToken}}).then(function (response) {
                    return response.json();
                }).then(function (data) {
                    content.replaceChildren();
                    setVisible(loading, false);

                    if (data.status !== "success") {
                        return;
                    }

                    data.data.forEach(function (param, index) {
                        var id = "opt-crypto_param_" + index;
                        var div = document.createElement("div");
                        var label = document.createElement("label");
                        var title = document.createElement("span");
                        label.htmlFor = id;
                        title.textContent = param["name"];

                        var input = null;
                        switch (param["type"]) {
                            case "choice":
                                input = document.createElement("select");
                                param["choices"].forEach(function (item) {
                                    var option = document.createElement("option");
                                    option.value = item;
                                    option.textContent = item;
                                    option.selected = param["value"] === item;
                                    input.appendChild(option);
                                });
                                break;
                            case "int":
                                input = document.createElement("input");
                                input.type = "number";
                                input.step = 1;
                                input.min = param["min"];
                                input.max = param["max"];
                                input.value = param["value"];
                                break;
                            default:
                                break;
                        }

                        label.appendChild(title);
                        div.appendChild(label);
                        if (input !== null) {
                            input.id = id;
                            input.name = id;
                            div.appendChild(input);
                        }
                        content.appendChild(div);
                        setVisible(content, true);
                    });
                }).catch(function () {
                    setVisible(loading, false);
                });
            };

            cryptoClass.addEventListener("change", cryptoChanged);
            cryptoChanged();
        };

        document.getElementById("db-driver").addEventListener("change", function () {
            var isMysql = this.value === "mysql";
            ["db-ssl_ca", "db-ssl_cert", "db-ssl_key"].forEach(function (id) {
                setVisible(document.getElementById(id).parentNode.parentNode, isMysql);
            });
        });

        document.getElementById("user_sql-db_connection_verify").addEventListener("click", function () {
            click("/apps/user_sql/settings/db/verify");
        });

        document.getElementById("user_sql-clear_cache").addEventListener("click", function () {
            click("/apps/user_sql/settings/cache/clear");
        });

        document.getElementById("user_sql-save").addEventListener("click", function () {
            click("/apps/user_sql/settings/properties");
        });

        autocomplete(
            "#db-table-user, #db-table-user_group, #db-table-group",
            "/apps/user_sql/settings/autocomplete/table"
        );

        autocomplete(
            "#db-table-user-column-uid, #db-table-user-column-username, #db-table-user-column-email, #db-table-user-column-quota, #db-table-user-column-home, #db-table-user-column-password, #db-table-user-column-name, #db-table-user-column-active, #db-table-user-column-disabled, #db-table-user-column-avatar, #db-table-user-column-salt",
            "/apps/user_sql/settings/autocomplete/table/user"
        );

        autocomplete(
            "#db-table-user_group-column-uid, #db-table-user_group-column-gid",
            "/apps/user_sql/settings/autocomplete/table/user_group"
        );

        autocomplete(
            "#db-table-group-column-admin, #db-table-group-column-name, #db-table-group-column-gid",
            "/apps/user_sql/settings/autocomplete/table/group"
        );

        cryptoParams();
    };

    var init = function () {
        var form = document.getElementById("user_sql");
        if (form !== null) {
            adminSettingsUI(form);
        }
    };

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();
