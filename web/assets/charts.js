/*
 * charts.js - a tiny chart drawer (line and bar charts as SVG).
 * No library is needed, so the site works with no internet connection.
 *
 * A chart is described in the page like this:
 *   <div class="chart" data-chart='{"type":"line","labels":[...],"series":[{"name":"Orders","values":[...],"color":"#1f5f8b"}]}'></div>
 * and this script turns every such element into an SVG picture.
 */
(function () {
    "use strict";
    var NS = "http://www.w3.org/2000/svg";

    function el(name, attrs, text) {
        var node = document.createElementNS(NS, name);
        for (var k in attrs) { node.setAttribute(k, attrs[k]); }
        if (text !== undefined) { node.textContent = text; }
        return node;
    }

    // Pick "nice" round numbers for the vertical axis (e.g. 0, 500, 1000)
    function niceMax(v) {
        if (v <= 0) { return 1; }
        var p = Math.pow(10, Math.floor(Math.log10(v)));
        var n = v / p;
        var m = n <= 1 ? 1 : n <= 2 ? 2 : n <= 5 ? 5 : 10;
        return m * p;
    }

    function fmt(n) {
        return Math.abs(n) >= 1000 ? (n / 1000).toFixed(n % 1000 === 0 ? 0 : 1) + "k" : String(Math.round(n * 10) / 10);
    }

    function frame(cfg, W, H, pad, yMax) {
        var svg = el("svg", { viewBox: "0 0 " + W + " " + H, role: "img", "aria-label": cfg.title || "chart" });
        for (var i = 0; i <= 4; i++) {
            var y = pad.t + (H - pad.t - pad.b) * (1 - i / 4);
            svg.appendChild(el("line", { x1: pad.l, x2: W - pad.r, y1: y, y2: y, stroke: "#e2e8f0", "stroke-width": 1 }));
            svg.appendChild(el("text", { x: pad.l - 6, y: y + 4, "text-anchor": "end" }, fmt(yMax * i / 4)));
        }
        return svg;
    }

    function lineChart(host, cfg) {
        var W = 640, H = 260, pad = { l: 46, r: 26, t: 12, b: 30 };
        var all = [];
        cfg.series.forEach(function (s) { s.values.forEach(function (v) { if (v !== null) { all.push(v); } }); });
        var yMax = niceMax(Math.max.apply(null, all.concat([1])) * 1.05);
        var svg = frame(cfg, W, H, pad, yMax);
        var n = cfg.labels.length;
        var x = function (i) { return pad.l + (n === 1 ? 0.5 : i / (n - 1)) * (W - pad.l - pad.r); };
        var y = function (v) { return pad.t + (H - pad.t - pad.b) * (1 - v / yMax); };
        var step = Math.max(1, Math.ceil(n / 8));
        cfg.labels.forEach(function (lab, i) {
            // show every 'step'-th label, plus the last one unless it would overlap the previous label
            if (i === n - 1 ? (n - 1) % step === 0 || (n - 1) % step >= step / 2 : i % step === 0) {
                svg.appendChild(el("text", { x: x(i), y: H - 8, "text-anchor": "middle" }, lab));
            }
        });
        cfg.series.forEach(function (s) {
            var d = "", started = false;
            s.values.forEach(function (v, i) {
                if (v === null) { started = false; return; }
                d += (started ? " L" : " M") + x(i).toFixed(1) + " " + y(v).toFixed(1);
                started = true;
            });
            var attrs = { d: d, fill: "none", stroke: s.color || "#1f5f8b", "stroke-width": 2.2, "stroke-linejoin": "round" };
            if (s.dashed) { attrs["stroke-dasharray"] = "5 4"; }
            svg.appendChild(el("path", attrs));
            s.values.forEach(function (v, i) {
                if (v === null) { return; }
                if (n > 30 && !(s.markLast && i === n - 1)) { return; }
                var c = el("circle", { cx: x(i), cy: y(v), r: s.markLast && i === n - 1 ? 5 : 3, fill: s.color || "#1f5f8b" });
                c.appendChild(el("title", {}, s.name + ", " + cfg.labels[i] + ": " + Math.round(v)));
                svg.appendChild(c);
            });
        });
        host.appendChild(svg);
        legend(host, cfg.series);
    }

    function barChart(host, cfg) {
        var rowH = 30, pad = { l: 120, r: 60, t: 8, b: 8 };
        var W = 640, H = pad.t + pad.b + rowH * cfg.labels.length;
        var max = niceMax(Math.max.apply(null, cfg.values.concat([1])) * 1.05);
        var svg = el("svg", { viewBox: "0 0 " + W + " " + H, role: "img", "aria-label": cfg.title || "chart" });
        cfg.labels.forEach(function (lab, i) {
            var y = pad.t + i * rowH;
            var w = (W - pad.l - pad.r) * cfg.values[i] / max;
            svg.appendChild(el("text", { x: pad.l - 8, y: y + 17, "text-anchor": "end" }, lab));
            var r = el("rect", { x: pad.l, y: y + 4, width: Math.max(w, 1), height: rowH - 10, rx: 3, fill: cfg.color || "#1f5f8b" });
            r.appendChild(el("title", {}, lab + ": " + cfg.values[i] + (cfg.unit || "")));
            svg.appendChild(r);
            svg.appendChild(el("text", { x: pad.l + w + 6, y: y + 17 }, cfg.values[i] + (cfg.unit || "")));
        });
        host.appendChild(svg);
    }

    function legend(host, series) {
        if (series.length < 2) { return; }
        var box = document.createElement("div");
        box.className = "legend";
        series.forEach(function (s) {
            var item = document.createElement("span");
            var swatch = document.createElement("i");
            swatch.style.background = s.color || "#1f5f8b";
            item.appendChild(swatch);
            item.appendChild(document.createTextNode(s.name));
            box.appendChild(item);
        });
        host.appendChild(box);
    }

    document.addEventListener("DOMContentLoaded", function () {
        var hosts = document.querySelectorAll("[data-chart]");
        Array.prototype.forEach.call(hosts, function (host) {
            try {
                var cfg = JSON.parse(host.getAttribute("data-chart"));
                if (cfg.type === "bar") { barChart(host, cfg); } else { lineChart(host, cfg); }
            } catch (err) {
                host.textContent = "Chart could not be drawn.";
            }
        });
    });
})();
