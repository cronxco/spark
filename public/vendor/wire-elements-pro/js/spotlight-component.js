(() => {
    var t = {
            757: (t, e, r) => {
                t.exports = r(666);
            },
            666: (t) => {
                var e = (function (t) {
                    "use strict";
                    var e,
                        r = Object.prototype,
                        n = r.hasOwnProperty,
                        o = "function" == typeof Symbol ? Symbol : {},
                        i = o.iterator || "@@iterator",
                        c = o.asyncIterator || "@@asyncIterator",
                        s = o.toStringTag || "@@toStringTag";
                    function u(t, e, r) {
                        return (
                            Object.defineProperty(t, e, {
                                value: r,
                                enumerable: !0,
                                configurable: !0,
                                writable: !0,
                            }),
                            t[e]
                        );
                    }
                    try {
                        u({}, "");
                    } catch (t) {
                        u = function (t, e, r) {
                            return (t[e] = r);
                        };
                    }
                    function a(t, e, r, n) {
                        var o = e && e.prototype instanceof y ? e : y,
                            i = Object.create(o.prototype),
                            c = new I(n || []);
                        return (
                            (i._invoke = (function (t, e, r) {
                                var n = h;
                                return function (o, i) {
                                    if (n === p)
                                        throw new Error(
                                            "Generator is already running",
                                        );
                                    if (n === d) {
                                        if ("throw" === o) throw i;
                                        return _();
                                    }
                                    for (r.method = o, r.arg = i; ; ) {
                                        var c = r.delegate;
                                        if (c) {
                                            var s = S(c, r);
                                            if (s) {
                                                if (s === v) continue;
                                                return s;
                                            }
                                        }
                                        if ("next" === r.method)
                                            r.sent = r._sent = r.arg;
                                        else if ("throw" === r.method) {
                                            if (n === h) throw ((n = d), r.arg);
                                            r.dispatchException(r.arg);
                                        } else
                                            "return" === r.method &&
                                                r.abrupt("return", r.arg);
                                        n = p;
                                        var u = l(t, e, r);
                                        if ("normal" === u.type) {
                                            if (
                                                ((n = r.done ? d : f),
                                                u.arg === v)
                                            )
                                                continue;
                                            return {
                                                value: u.arg,
                                                done: r.done,
                                            };
                                        }
                                        "throw" === u.type &&
                                            ((n = d),
                                            (r.method = "throw"),
                                            (r.arg = u.arg));
                                    }
                                };
                            })(t, r, c)),
                            i
                        );
                    }
                    function l(t, e, r) {
                        try {
                            return { type: "normal", arg: t.call(e, r) };
                        } catch (t) {
                            return { type: "throw", arg: t };
                        }
                    }
                    t.wrap = a;
                    var h = "suspendedStart",
                        f = "suspendedYield",
                        p = "executing",
                        d = "completed",
                        v = {};
                    function y() {}
                    function g() {}
                    function m() {}
                    var w = {};
                    u(w, i, function () {
                        return this;
                    });
                    var x = Object.getPrototypeOf,
                        b = x && x(x(T([])));
                    b && b !== r && n.call(b, i) && (w = b);
                    var k = (m.prototype = y.prototype = Object.create(w));
                    function L(t) {
                        ["next", "throw", "return"].forEach(function (e) {
                            u(t, e, function (t) {
                                return this._invoke(e, t);
                            });
                        });
                    }
                    function G(t, e) {
                        function r(o, i, c, s) {
                            var u = l(t[o], t, i);
                            if ("throw" !== u.type) {
                                var a = u.arg,
                                    h = a.value;
                                return h &&
                                    "object" == typeof h &&
                                    n.call(h, "__await")
                                    ? e.resolve(h.__await).then(
                                          function (t) {
                                              r("next", t, c, s);
                                          },
                                          function (t) {
                                              r("throw", t, c, s);
                                          },
                                      )
                                    : e.resolve(h).then(
                                          function (t) {
                                              ((a.value = t), c(a));
                                          },
                                          function (t) {
                                              return r("throw", t, c, s);
                                          },
                                      );
                            }
                            s(u.arg);
                        }
                        var o;
                        this._invoke = function (t, n) {
                            function i() {
                                return new e(function (e, o) {
                                    r(t, n, e, o);
                                });
                            }
                            return (o = o ? o.then(i, i) : i());
                        };
                    }
                    function S(t, r) {
                        var n = t.iterator[r.method];
                        if (n === e) {
                            if (((r.delegate = null), "throw" === r.method)) {
                                if (
                                    t.iterator.return &&
                                    ((r.method = "return"),
                                    (r.arg = e),
                                    S(t, r),
                                    "throw" === r.method)
                                )
                                    return v;
                                ((r.method = "throw"),
                                    (r.arg = new TypeError(
                                        "The iterator does not provide a 'throw' method",
                                    )));
                            }
                            return v;
                        }
                        var o = l(n, t.iterator, r.arg);
                        if ("throw" === o.type)
                            return (
                                (r.method = "throw"),
                                (r.arg = o.arg),
                                (r.delegate = null),
                                v
                            );
                        var i = o.arg;
                        return i
                            ? i.done
                                ? ((r[t.resultName] = i.value),
                                  (r.next = t.nextLoc),
                                  "return" !== r.method &&
                                      ((r.method = "next"), (r.arg = e)),
                                  (r.delegate = null),
                                  v)
                                : i
                            : ((r.method = "throw"),
                              (r.arg = new TypeError(
                                  "iterator result is not an object",
                              )),
                              (r.delegate = null),
                              v);
                    }
                    function E(t) {
                        var e = { tryLoc: t[0] };
                        (1 in t && (e.catchLoc = t[1]),
                            2 in t &&
                                ((e.finallyLoc = t[2]), (e.afterLoc = t[3])),
                            this.tryEntries.push(e));
                    }
                    function $(t) {
                        var e = t.completion || {};
                        ((e.type = "normal"), delete e.arg, (t.completion = e));
                    }
                    function I(t) {
                        ((this.tryEntries = [{ tryLoc: "root" }]),
                            t.forEach(E, this),
                            this.reset(!0));
                    }
                    function T(t) {
                        if (t) {
                            var r = t[i];
                            if (r) return r.call(t);
                            if ("function" == typeof t.next) return t;
                            if (!isNaN(t.length)) {
                                var o = -1,
                                    c = function r() {
                                        for (; ++o < t.length; )
                                            if (n.call(t, o))
                                                return (
                                                    (r.value = t[o]),
                                                    (r.done = !1),
                                                    r
                                                );
                                        return (
                                            (r.value = e),
                                            (r.done = !0),
                                            r
                                        );
                                    };
                                return (c.next = c);
                            }
                        }
                        return { next: _ };
                    }
                    function _() {
                        return { value: e, done: !0 };
                    }
                    return (
                        (g.prototype = m),
                        u(k, "constructor", m),
                        u(m, "constructor", g),
                        (g.displayName = u(m, s, "GeneratorFunction")),
                        (t.isGeneratorFunction = function (t) {
                            var e = "function" == typeof t && t.constructor;
                            return (
                                !!e &&
                                (e === g ||
                                    "GeneratorFunction" ===
                                        (e.displayName || e.name))
                            );
                        }),
                        (t.mark = function (t) {
                            return (
                                Object.setPrototypeOf
                                    ? Object.setPrototypeOf(t, m)
                                    : ((t.__proto__ = m),
                                      u(t, s, "GeneratorFunction")),
                                (t.prototype = Object.create(k)),
                                t
                            );
                        }),
                        (t.awrap = function (t) {
                            return { __await: t };
                        }),
                        L(G.prototype),
                        u(G.prototype, c, function () {
                            return this;
                        }),
                        (t.AsyncIterator = G),
                        (t.async = function (e, r, n, o, i) {
                            void 0 === i && (i = Promise);
                            var c = new G(a(e, r, n, o), i);
                            return t.isGeneratorFunction(r)
                                ? c
                                : c.next().then(function (t) {
                                      return t.done ? t.value : c.next();
                                  });
                        }),
                        L(k),
                        u(k, s, "Generator"),
                        u(k, i, function () {
                            return this;
                        }),
                        u(k, "toString", function () {
                            return "[object Generator]";
                        }),
                        (t.keys = function (t) {
                            var e = [];
                            for (var r in t) e.push(r);
                            return (
                                e.reverse(),
                                function r() {
                                    for (; e.length; ) {
                                        var n = e.pop();
                                        if (n in t)
                                            return (
                                                (r.value = n),
                                                (r.done = !1),
                                                r
                                            );
                                    }
                                    return ((r.done = !0), r);
                                }
                            );
                        }),
                        (t.values = T),
                        (I.prototype = {
                            constructor: I,
                            reset: function (t) {
                                if (
                                    ((this.prev = 0),
                                    (this.next = 0),
                                    (this.sent = this._sent = e),
                                    (this.done = !1),
                                    (this.delegate = null),
                                    (this.method = "next"),
                                    (this.arg = e),
                                    this.tryEntries.forEach($),
                                    !t)
                                )
                                    for (var r in this)
                                        "t" === r.charAt(0) &&
                                            n.call(this, r) &&
                                            !isNaN(+r.slice(1)) &&
                                            (this[r] = e);
                            },
                            stop: function () {
                                this.done = !0;
                                var t = this.tryEntries[0].completion;
                                if ("throw" === t.type) throw t.arg;
                                return this.rval;
                            },
                            dispatchException: function (t) {
                                if (this.done) throw t;
                                var r = this;
                                function o(n, o) {
                                    return (
                                        (s.type = "throw"),
                                        (s.arg = t),
                                        (r.next = n),
                                        o && ((r.method = "next"), (r.arg = e)),
                                        !!o
                                    );
                                }
                                for (
                                    var i = this.tryEntries.length - 1;
                                    i >= 0;
                                    --i
                                ) {
                                    var c = this.tryEntries[i],
                                        s = c.completion;
                                    if ("root" === c.tryLoc) return o("end");
                                    if (c.tryLoc <= this.prev) {
                                        var u = n.call(c, "catchLoc"),
                                            a = n.call(c, "finallyLoc");
                                        if (u && a) {
                                            if (this.prev < c.catchLoc)
                                                return o(c.catchLoc, !0);
                                            if (this.prev < c.finallyLoc)
                                                return o(c.finallyLoc);
                                        } else if (u) {
                                            if (this.prev < c.catchLoc)
                                                return o(c.catchLoc, !0);
                                        } else {
                                            if (!a)
                                                throw new Error(
                                                    "try statement without catch or finally",
                                                );
                                            if (this.prev < c.finallyLoc)
                                                return o(c.finallyLoc);
                                        }
                                    }
                                }
                            },
                            abrupt: function (t, e) {
                                for (
                                    var r = this.tryEntries.length - 1;
                                    r >= 0;
                                    --r
                                ) {
                                    var o = this.tryEntries[r];
                                    if (
                                        o.tryLoc <= this.prev &&
                                        n.call(o, "finallyLoc") &&
                                        this.prev < o.finallyLoc
                                    ) {
                                        var i = o;
                                        break;
                                    }
                                }
                                i &&
                                    ("break" === t || "continue" === t) &&
                                    i.tryLoc <= e &&
                                    e <= i.finallyLoc &&
                                    (i = null);
                                var c = i ? i.completion : {};
                                return (
                                    (c.type = t),
                                    (c.arg = e),
                                    i
                                        ? ((this.method = "next"),
                                          (this.next = i.finallyLoc),
                                          v)
                                        : this.complete(c)
                                );
                            },
                            complete: function (t, e) {
                                if ("throw" === t.type) throw t.arg;
                                return (
                                    "break" === t.type || "continue" === t.type
                                        ? (this.next = t.arg)
                                        : "return" === t.type
                                          ? ((this.rval = this.arg = t.arg),
                                            (this.method = "return"),
                                            (this.next = "end"))
                                          : "normal" === t.type &&
                                            e &&
                                            (this.next = e),
                                    v
                                );
                            },
                            finish: function (t) {
                                for (
                                    var e = this.tryEntries.length - 1;
                                    e >= 0;
                                    --e
                                ) {
                                    var r = this.tryEntries[e];
                                    if (r.finallyLoc === t)
                                        return (
                                            this.complete(
                                                r.completion,
                                                r.afterLoc,
                                            ),
                                            $(r),
                                            v
                                        );
                                }
                            },
                            catch: function (t) {
                                for (
                                    var e = this.tryEntries.length - 1;
                                    e >= 0;
                                    --e
                                ) {
                                    var r = this.tryEntries[e];
                                    if (r.tryLoc === t) {
                                        var n = r.completion;
                                        if ("throw" === n.type) {
                                            var o = n.arg;
                                            $(r);
                                        }
                                        return o;
                                    }
                                }
                                throw new Error("illegal catch attempt");
                            },
                            delegateYield: function (t, r, n) {
                                return (
                                    (this.delegate = {
                                        iterator: T(t),
                                        resultName: r,
                                        nextLoc: n,
                                    }),
                                    "next" === this.method && (this.arg = e),
                                    v
                                );
                            },
                        }),
                        t
                    );
                })(t.exports);
                try {
                    regeneratorRuntime = e;
                } catch (t) {
                    "object" == typeof globalThis
                        ? (globalThis.regeneratorRuntime = e)
                        : Function("r", "regeneratorRuntime = r")(e);
                }
            },
        },
        e = {};
    function r(n) {
        var o = e[n];
        if (void 0 !== o) return o.exports;
        var i = (e[n] = { exports: {} });
        return (t[n](i, i.exports, r), i.exports);
    }
    ((r.n = (t) => {
        var e = t && t.__esModule ? () => t.default : () => t;
        return (r.d(e, { a: e }), e);
    }),
        (r.d = (t, e) => {
            for (var n in e)
                r.o(e, n) &&
                    !r.o(t, n) &&
                    Object.defineProperty(t, n, { enumerable: !0, get: e[n] });
        }),
        (r.o = (t, e) => Object.prototype.hasOwnProperty.call(t, e)),
        (() => {
            "use strict";
            var t = r(757),
                e = r.n(t);
            function n(t, e, r) {
                return (
                    e in t
                        ? Object.defineProperty(t, e, {
                              value: r,
                              enumerable: !0,
                              configurable: !0,
                              writable: !0,
                          })
                        : (t[e] = r),
                    t
                );
            }
            function o(t, e, r, n, o, i, c) {
                try {
                    var s = t[i](c),
                        u = s.value;
                } catch (t) {
                    return void r(t);
                }
                s.done ? e(u) : Promise.resolve(u).then(n, o);
            }
            function i(t) {
                return function () {
                    var e = this,
                        r = arguments;
                    return new Promise(function (n, i) {
                        var c = t.apply(e, r);
                        function s(t) {
                            o(c, n, i, s, u, "next", t);
                        }
                        function u(t) {
                            o(c, n, i, s, u, "throw", t);
                        }
                        s(void 0);
                    });
                };
            }
            document.addEventListener("alpine:init", function () {
                Alpine.data("WepSpotlightComponent", function (t) {
                    var r;
                    return {
                        tokens: window.Livewire.find(t).$entangle(
                            "activeTokens",
                            !0,
                        ),
                        active: window.Livewire.find(t).$entangle("active", !0),
                        selectedGroup: 0,
                        selectedItem: 0,
                        query: window.Livewire.find(t).$entangle("query", !0),
                        listeners: [],
                        input:
                            ((r = {}),
                            n(r, "x-ref", "input"),
                            n(r, "@keydown.backspace", function () {
                                var t = this;
                                return i(
                                    e().mark(function r() {
                                        return e().wrap(function (e) {
                                            for (;;)
                                                switch ((e.prev = e.next)) {
                                                    case 0:
                                                        if (
                                                            !(
                                                                "" ===
                                                                    t.$el
                                                                        .value &&
                                                                t.tokens
                                                                    .length > 0
                                                            )
                                                        ) {
                                                            e.next = 7;
                                                            break;
                                                        }
                                                        return (
                                                            (e.next = 3),
                                                            t.$wire.popScope()
                                                        );
                                                    case 3:
                                                        ((t.selectedGroup = 0),
                                                            (t.selectedItem = 0),
                                                            t.$nextTick(
                                                                function () {
                                                                    t.resetScroll();
                                                                },
                                                            ),
                                                            t.focus());
                                                    case 7:
                                                    case "end":
                                                        return e.stop();
                                                }
                                        }, r);
                                    }),
                                )();
                            }),
                            n(r, "@keydown.backspace.shift", function () {
                                var t = this;
                                return i(
                                    e().mark(function r() {
                                        return e().wrap(function (e) {
                                            for (;;)
                                                switch ((e.prev = e.next)) {
                                                    case 0:
                                                        t.resetScope();
                                                    case 1:
                                                    case "end":
                                                        return e.stop();
                                                }
                                        }, r);
                                    }),
                                )();
                            }),
                            n(r, "@keydown.arrow-up", function (t) {
                                (t.preventDefault(), this.selectUp());
                            }),
                            n(r, "@keydown.arrow-down", function (t) {
                                (t.preventDefault(), this.selectDown());
                            }),
                            n(r, "@keydown.enter", function (t) {
                                (t.preventDefault(), this.select());
                            }),
                            n(r, "@keydown.tab", function (t) {
                                (t.preventDefault(),
                                    this.selectionHasTokens() &&
                                        (this.applyScope(), this.resetQuery()));
                            }),
                            n(r, "@keyup.tab", function (t) {
                                t.preventDefault();
                            }),
                            r),
                        init: function () {
                            var t = this;
                            (this.$watch("query", function () {
                                ((t.selectedItem = 0), (t.selectedGroup = 0));
                            }),
                                this.$watch("active", function () {
                                    t.active && t.focus();
                                }),
                                this.listeners.push(
                                    Livewire.on(
                                        "spotlight.toggle",
                                        function (e) {
                                            t.toggle(e);
                                        },
                                    ),
                                ));
                        },
                        destroy: function () {
                            this.listeners.forEach(function (t) {
                                t();
                            });
                        },
                        focus: function () {
                            var t = this;
                            setTimeout(function () {
                                t.$focus.focus(t.$refs.input);
                            }, 100);
                        },
                        close: function () {
                            this.active = !1;
                        },
                        toggle: function (t) {
                            (null != t && t.query && (this.query = t.query),
                                (this.active = !this.active));
                        },
                        resetQuery: function () {
                            ((this.query = ""),
                                (this.selectedItem = 0),
                                (this.selectedGroup = 0),
                                this.focus());
                        },
                        resetScope: function () {
                            (this.$wire.clearScope(),
                                this.resetScroll(),
                                this.focus());
                        },
                        selection: function () {
                            var t,
                                e,
                                r,
                                n =
                                    null ===
                                        (r = (
                                            null !==
                                                (t =
                                                    null ===
                                                        (e =
                                                            this.$refs
                                                                .resultGroups) ||
                                                    void 0 === e
                                                        ? void 0
                                                        : e.children) &&
                                            void 0 !== t
                                                ? t
                                                : []
                                        )[this.selectedGroup]) || void 0 === r
                                        ? void 0
                                        : r.querySelectorAll("ul>li")[
                                              this.selectedItem
                                          ];
                            return n ? Alpine.$data(n) : null;
                        },
                        selectionHasTokens: function () {
                            var t, e;
                            return (
                                0 !==
                                (null === (t = this.selection()) ||
                                void 0 === t ||
                                null === (e = t.tokens) ||
                                void 0 === e
                                    ? void 0
                                    : e.length)
                            );
                        },
                        select: function () {
                            this.selection().action
                                ? this.$wire.runAction(this.selection().action)
                                : this.selection().tokens && this.applyScope();
                        },
                        applyScope: function () {
                            var t = this;
                            (this.$wire.applyTokens(this.selection().tokens),
                                this.$nextTick(function () {
                                    (t.$focus.focus(t.$refs.input),
                                        t.resetScroll());
                                }));
                        },
                        resetScroll: function () {
                            this.$refs.resultGroups.scrollTop = 0;
                        },
                        selectUp: function () {
                            var t = this,
                                e = this.$refs.resultGroups.children;
                            (Math.max(0, this.selectedItem - 1) ===
                            this.selectedItem
                                ? ((this.selectedGroup =
                                      this.selectedGroup - 1 == -1
                                          ? e.length - 1
                                          : Math.min(
                                                e.length - 1,
                                                this.selectedGroup - 1,
                                            )),
                                  (this.selectedItem =
                                      e[this.selectedGroup].querySelectorAll(
                                          "ul>li",
                                      ).length - 1))
                                : (this.selectedItem = Math.min(
                                      e[this.selectedGroup].querySelectorAll(
                                          "ul>li",
                                      ).length - 1,
                                      this.selectedItem - 1,
                                  )),
                                this.$nextTick(function () {
                                    var r;
                                    null ===
                                        (r =
                                            e[t.selectedGroup].querySelectorAll(
                                                "ul>li",
                                            )[t.selectedItem - 1]) ||
                                        void 0 === r ||
                                        r.scrollIntoView({ block: "nearest" });
                                }));
                        },
                        selectDown: function () {
                            var t = this,
                                e = this.$refs.resultGroups.children;
                            (Math.min(
                                e[this.selectedGroup].querySelectorAll("ul>li")
                                    .length - 1,
                                this.selectedItem + 1,
                            ) === this.selectedItem
                                ? ((this.selectedGroup =
                                      e.length - 1 === this.selectedGroup
                                          ? 0
                                          : Math.min(
                                                e.length - 1,
                                                this.selectedGroup + 1,
                                            )),
                                  (this.selectedItem = 0))
                                : (this.selectedItem = Math.min(
                                      e[this.selectedGroup].querySelectorAll(
                                          "ul>li",
                                      ).length - 1,
                                      this.selectedItem + 1,
                                  )),
                                this.$nextTick(function () {
                                    var r;
                                    null ===
                                        (r =
                                            e[t.selectedGroup].querySelectorAll(
                                                "ul>li",
                                            )[t.selectedItem + 1]) ||
                                        void 0 === r ||
                                        r.scrollIntoView({ block: "nearest" });
                                }));
                        },
                    };
                });
            });
        })());
})();
