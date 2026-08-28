(() => {
    "use strict";

    const storageKey = "passiontech.erp.remembered-users";

    const form = document.getElementById("pt-login-form");
    const picker = document.getElementById("pt-account-picker");
    const list = document.getElementById("pt-account-list");
    const useAnother = document.getElementById("pt-use-another");
    const back = document.getElementById("pt-back-to-users");
    const login = document.getElementById("login");
    const password = document.getElementById("password");
    const passwordToggle =
        document.getElementById("pt-password-toggle");

    if (
        !form ||
        !picker ||
        !list ||
        !useAnother ||
        !back ||
        !login ||
        !password
    ) {
        return;
    }

    const readUsers = () => {
        try {
            const value = JSON.parse(
                localStorage.getItem(storageKey) || "[]"
            );

            return Array.isArray(value)
                ? value.filter(
                    (item) =>
                        typeof item === "string" &&
                        item.trim() !== ""
                )
                : [];
        } catch (_) {
            return [];
        }
    };

    const writeUsers = (users) => {
        try {
            localStorage.setItem(
                storageKey,
                JSON.stringify(users.slice(0, 6))
            );
        } catch (_) {
            // Browser storage is optional.
        }
    };

    const remember = (value) => {
        const normalized = value.trim();

        if (!normalized) {
            return;
        }

        const users = readUsers().filter(
            (item) =>
                item.toLowerCase() !==
                normalized.toLowerCase()
        );

        users.unshift(normalized);
        writeUsers(users);
    };

    const initials = (value) =>
        value.trim().charAt(0).toUpperCase() || "U";

    const showForm = (user = "") => {
        picker.hidden = true;
        form.hidden = false;

        if (user !== "") {
            login.value = user;
            password.value = "";
            back.hidden = false;
            password.focus();
        } else {
            login.value = "";
            password.value = "";
            back.hidden = readUsers().length === 0;
            login.focus();
        }
    };

    const renderPicker = () => {
        const users = readUsers();

        list.replaceChildren();

        if (users.length === 0) {
            showForm();
            return;
        }

        users.forEach((user, index) => {
            const row = document.createElement("button");
            row.type = "button";
            row.className = "pt-account-row";

            const avatar =
                document.createElement("span");
            avatar.className = "pt-account-avatar";
            avatar.textContent = initials(user);

            const palette = [
                "#69ad35",
                "#a936c0",
                "#2daf43",
                "#43a485",
                "#377ebd",
                "#d37a32"
            ];

            avatar.style.background =
                palette[index % palette.length];

            const name =
                document.createElement("span");
            name.className = "pt-account-name";
            name.textContent = user;

            const remove =
                document.createElement("button");
            remove.type = "button";
            remove.className = "pt-account-remove";
            remove.setAttribute(
                "aria-label",
                `Remove ${user}`
            );
            remove.textContent = "×";

            remove.addEventListener(
                "click",
                (event) => {
                    event.preventDefault();
                    event.stopPropagation();

                    writeUsers(
                        readUsers().filter(
                            (item) => item !== user
                        )
                    );

                    renderPicker();
                }
            );

            row.addEventListener(
                "click",
                () => showForm(user)
            );

            row.append(avatar, name, remove);
            list.append(row);
        });

        form.hidden = true;
        picker.hidden = false;
    };

    useAnother.addEventListener(
        "click",
        () => showForm()
    );

    back.addEventListener(
        "click",
        renderPicker
    );

    form.addEventListener(
        "submit",
        () => remember(login.value)
    );

    if (passwordToggle) {
        passwordToggle.addEventListener(
            "click",
            () => {
                const showing =
                    password.type === "text";

                password.type =
                    showing ? "password" : "text";

                passwordToggle.textContent =
                    showing ? "Show" : "Hide";

                passwordToggle.setAttribute(
                    "aria-label",
                    showing
                        ? "Show password"
                        : "Hide password"
                );
            }
        );
    }

    const hasError =
        form.dataset.hasError === "1";

    const oldLogin =
        (form.dataset.oldLogin || "").trim();

    if (hasError || oldLogin !== "") {
        form.hidden = false;
        picker.hidden = true;
        back.hidden = readUsers().length === 0;
    } else {
        renderPicker();
    }
})();