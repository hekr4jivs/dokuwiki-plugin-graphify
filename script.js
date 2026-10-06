/* Copyright (c) 2026 Heinrich Krupp. SPDX-License-Identifier: GPL-2.0-only */
/* DOKUWIKI:include_once vendor/vis-network.min.js */

/* JSON is data only. Never insert author-provided HTML or accept vis options. */
(function () {
    'use strict';
    if (window.GraphifyWiki) return;
    var instances = [];
    var palette = ['#2c5eaa', '#cf791b', '#ae3434', '#27838e', '#438347', '#8b65af', '#a96688', '#736350'];
    function element(tag, className, text) {
        var el = document.createElement(tag);
        if (className) el.className = className;
        if (text !== undefined) el.textContent = text;
        return el;
    }
    function hull(points) {
        points.sort(function (a, b) { return a.x - b.x || a.y - b.y; });
        function cross(o, a, b) { return (a.x - o.x) * (b.y - o.y) - (a.y - o.y) * (b.x - o.x); }
        var lower = [], upper = [];
        points.forEach(function (p) {
            while (lower.length > 1 && cross(lower[lower.length - 2], lower[lower.length - 1], p) <= 0) lower.pop();
            lower.push(p);
        });
        points.slice().reverse().forEach(function (p) {
            while (upper.length > 1 && cross(upper[upper.length - 2], upper[upper.length - 1], p) <= 0) upper.pop();
            upper.push(p);
        });
        return lower.slice(0, -1).concat(upper.slice(0, -1));
    }
    function mount(root) {
        if (root.graphify) return root.graphify;
        var source = root.querySelector('.graphify-data');
        if (!source) return;
        var model;
        try { model = JSON.parse(source.textContent); }
        catch (e) { root.appendChild(element('p', 'graphify-error', 'Invalid graph JSON.')); return; }
        if (!window.vis || !window.vis.Network) {
            root.querySelector('.graphify-loading').textContent = 'Graph renderer library unavailable.'; return;
        }
        var loading = root.querySelector('.graphify-loading');
        if (loading) loading.remove();
        var arch = model.format === 'archify', selected = null, pathStart = null, dark = false, labels = arch;
        var groups = new Map(), hiddenGroups = new Set(), hiddenConfidence = new Set();
        var byId = new Map(model.nodes.map(function (n) { return [n.id, n]; }));
        var byEdge = new Map(model.edges.map(function (e) { return [e.id, e]; }));
        var adjacency = new Map(model.nodes.map(function (n) { return [n.id, []]; }));
        model.edges.forEach(function (e) {
            adjacency.get(e.from).push({id:e.to, edge:e.id, forward:true});
            adjacency.get(e.to).push({id:e.from, edge:e.id, forward:!model.directed});
        });
        model.nodes.forEach(function (n) {
            if (!groups.has(n.group)) groups.set(n.group, {label:n.groupLabel, color:palette[groups.size % palette.length], count:0});
            groups.get(n.group).count++;
        });
        var header = element('div', 'graphify-header');
        header.appendChild(element('strong', 'graphify-title', model.title));
        header.appendChild(element('span', 'graphify-count', model.nodes.length + ' nodes · ' + model.edges.length + ' edges · ' + model.format));
        root.appendChild(header);
        var toolbar = element('div', 'graphify-toolbar');
        function button(text, name, handler, parent) {
            var b = element('button', '', text); b.type = 'button'; b.dataset.action = name;
            b.addEventListener('click', handler); (parent || toolbar).appendChild(b); return b;
        }
        var searchLabel = element('label', 'graphify-search-label', 'Find node ');
        var search = element('input'); search.type = 'search'; search.placeholder = 'Name, ID or source';
        search.setAttribute('aria-label', 'Find graph node'); search.dataset.action = 'search';
        searchLabel.appendChild(search); toolbar.appendChild(searchLabel); root.appendChild(toolbar);
        var results = element('div', 'graphify-results'); results.hidden = true; root.appendChild(results);
        var body = element('div', 'graphify-body');
        var canvas = element('div', 'graphify-canvas'); canvas.tabIndex = 0; canvas.setAttribute('aria-label', 'Interactive graph: drag to pan, scroll to zoom, click a node for details');
        var aside = element('div', 'graphify-aside');
        var detail = element('div', 'graphify-detail'); detail.setAttribute('aria-live', 'polite');
        detail.appendChild(element('p', '', 'Click a node or edge to inspect it.'));
        var filters = element('div', 'graphify-filters'); filters.appendChild(element('strong', '', arch ? 'Component types' : 'Communities'));
        aside.appendChild(filters); aside.appendChild(detail);
        body.appendChild(canvas); body.appendChild(aside); root.appendChild(body);
        var status = element('p', 'graphify-status'); status.setAttribute('aria-live', 'polite'); root.appendChild(status);
        if (model.cards.length) {
            var cards = element('div', 'graphify-cards');
            model.cards.forEach(function (card) {
                var section = element('div', 'graphify-card'); section.appendChild(element('strong', '', card.title));
                var list = element('ul'); card.items.forEach(function (text) { list.appendChild(element('li', '', text)); });
                section.appendChild(list); cards.appendChild(section);
            }); root.appendChild(cards);
        }
        var nodes = new vis.DataSet(model.nodes.map(function (n) {
            var color = groups.get(n.group).color, label = n.label;
            var tooltip = element('div', '', n.label + (n.tag ? '\n' + n.tag : ''));
            var base = {id:n.id, label:label, title:tooltip, shape:arch ? 'box' : 'dot',
                color:{background:arch ? '#f4f7fb' : color, border:color, highlight:{background:'#e8effb',border:color}},
                font:{color:'#363635',size:arch ? 16 : 15,multi:false}, borderWidth:2,
                size:14 + Math.min(22, adjacency.get(n.id).length * 2)};
            if (arch) {
                base.label = [label, n.sublabel, n.tag].filter(Boolean).join('\n');
                base.x = n.x; base.y = n.y; base.physics = false;
                base.widthConstraint = {minimum:n.width, maximum:n.width};
                base.heightConstraint = {minimum:n.height}; base.margin = 12;
            }
            return base;
        }));
        function edgeColor(e) { return arch ? (e.variant === 'dashed' ? '#8b65af' : '#27838e') :
            ({EXTRACTED:'#2c5eaa',INFERRED:'#737373',AMBIGUOUS:'#b71918'}[e.confidence] || '#737373'); }
        var edges = new vis.DataSet(model.edges.map(function (e) {
            var tip = element('div', '', e.label + (e.confidence ? ' [' + e.confidence + ']' : ''));
            return {id:e.id,from:e.from,to:e.to,label:labels ? e.label : '',title:tip,arrows:model.directed ? 'to' : '',
                color:{color:edgeColor(e),highlight:'#b71918'},width:2,
                dashes:arch ? e.variant === 'dashed' : e.confidence === 'INFERRED' ? [8,5] : e.confidence === 'AMBIGUOUS' ? [2,5] : false,
                font:{size:12,color:'#363635',background:'#ffffff',strokeWidth:0,align:'horizontal',multi:false},
                smooth:arch ? false : {type:'continuous',roundness:0.15}};
        }));
        var network = new vis.Network(canvas, {nodes:nodes,edges:edges}, {
            autoResize:true, layout:{randomSeed:17}, interaction:{hover:true,tooltipDelay:150,keyboard:{enabled:true,bindToWindow:false}},
            physics:arch ? false : {solver:'barnesHut',stabilization:{iterations:180},barnesHut:{avoidOverlap:0.9,springLength:200}},
            nodes:{chosen:true},edges:{chosen:true}, manipulation:false
        });
        function fit() {
            network.fit({animation:false});
            if (!arch && network.getScale() > 1.2) network.moveTo({scale:1.2,animation:false});
        }
        if (!arch) network.once('stabilizationIterationsDone', function () { network.setOptions({physics:false}); fit(); });
        function focus(id) {
            selected = id; network.selectNodes([id]);
            network.focus(id, {scale:arch ? Math.max(network.getScale(),0.7) : 1.1,animation:false});
            showNode(id);
        }
        function metadata(value) { detail.appendChild(element('pre', 'graphify-metadata', JSON.stringify(value,null,2))); }
        function showNode(id) {
            var node = byId.get(id); if (!node) return;
            detail.replaceChildren(element('strong', '', node.label));
            detail.appendChild(element('p', '', 'ID: ' + id));
            detail.appendChild(element('p', '', adjacency.get(id).length + ' connections'));
            button('Set path start', 'path-start', function () { pathStart=id; status.textContent='Path starts at ' + node.label + '. Select a destination node.'; }, detail);
            if (pathStart && pathStart !== id) button('Trace path here', 'path-end', function () { trace(pathStart,id); }, detail);
            var neighbors = element('div', 'graphify-neighbors');
            Array.from(new Set(adjacency.get(id).map(function (link) { return link.id; }))).forEach(function (neighbor) {
                button(byId.get(neighbor).label, 'neighbor', function () { focus(neighbor); }, neighbors);
            }); detail.appendChild(neighbors); metadata(node.metadata);
        }
        function trace(from,to) {
            var queue=[from], seen=new Map([[from,null]]);
            for (var i=0;i<queue.length && !seen.has(to);i++) {
                adjacency.get(queue[i]).forEach(function (link) {
                    if (link.forward && !seen.has(link.id) && !nodes.get(link.id).hidden && !edges.get(link.edge).hidden) {
                        seen.set(link.id,{prev:queue[i],edge:link.edge}); queue.push(link.id);
                    }
                });
            }
            if (!seen.has(to)) { status.textContent='No visible ' + (model.directed ? 'directed ' : '') + 'path found.'; return; }
            var pathNodes=[to],pathEdges=[],step=to;
            while (step !== from) { var p=seen.get(step); pathEdges.push(p.edge); pathNodes.push(p.prev); step=p.prev; }
            network.setSelection({nodes:pathNodes,edges:pathEdges},{highlightEdges:false});
            status.textContent=pathNodes.reverse().map(function (id) { return byId.get(id).label; }).join(' → ');
        }
        function applyFilters() {
            nodes.update(model.nodes.map(function (n) { return {id:n.id,hidden:hiddenGroups.has(n.group)}; }));
            edges.update(model.edges.map(function (e) { return {id:e.id,hidden:hiddenGroups.has(byId.get(e.from).group) ||
                hiddenGroups.has(byId.get(e.to).group) || hiddenConfidence.has(e.confidence)}; }));
            status.textContent=nodes.get({filter:function (n) { return !n.hidden; }}).length + ' visible nodes'; network.redraw();
        }
        function checkbox(text, color, callback, name) {
            var label = element('label', 'graphify-filter'); var box=element('input'); box.type='checkbox'; box.checked=true;
            box.dataset.action=name; box.addEventListener('change',function () { callback(!box.checked); }); label.appendChild(box);
            var dot=element('span','graphify-dot'); dot.style.backgroundColor=color; label.appendChild(dot);
            label.appendChild(document.createTextNode(text)); filters.appendChild(label);
        }
        groups.forEach(function (group,id) { checkbox(group.label + ' (' + group.count + ')',group.color,function (hide) {
            if (hide) hiddenGroups.add(id); else hiddenGroups.delete(id); applyFilters();
        },'group'); });
        if (!arch) {
            filters.appendChild(element('strong', '', 'Relationship confidence'));
            Array.from(new Set(model.edges.map(function (e) { return e.confidence; }))).forEach(function (confidence) {
                checkbox(confidence,edgeColor({confidence:confidence}),function (hide) {
                    if (hide) hiddenConfidence.add(confidence); else hiddenConfidence.delete(confidence); applyFilters();
                },'confidence');
            });
        }
        search.addEventListener('input', function () {
            var query=search.value.trim().toLocaleLowerCase(); results.replaceChildren(); results.hidden=!query;
            if (!query) return;
            var matches=model.nodes.filter(function (n) {
                return !nodes.get(n.id).hidden && JSON.stringify([n.id,n.label,n.metadata.source_file,n.tag]).toLocaleLowerCase().includes(query);
            });
            results.appendChild(element('span','',matches.length + ' matches '));
            matches.slice(0,30).forEach(function (n) { button(n.label,'result',function () { focus(n.id); results.hidden=true; },results); });
        });
        button('−','zoom-out',function () { network.moveTo({scale:network.getScale()/1.25,animation:false}); });
        button('+','zoom-in',function () { network.moveTo({scale:network.getScale()*1.25,animation:false}); });
        button('Fit','fit',fit);
        button('Edge labels','labels',function () {
            labels=!labels; edges.update(model.edges.map(function (e) { return {id:e.id,label:labels ? e.label : ''}; }));
        });
        button('Details & filters','details',function () { root.classList.toggle('graphify-details'); });
        button('Theme','theme',function () {
            dark=!dark; root.classList.toggle('graphify-dark',dark);
            nodes.update(model.nodes.map(function (n) { return {id:n.id,font:{color:dark ? '#f2f2f2' : '#363635'},
                color:{background:arch ? (dark ? '#363635' : '#f4f7fb') : groups.get(n.group).color}}; }));
            edges.update(model.edges.map(function (e) { return {id:e.id,font:{color:dark ? '#f2f2f2' : '#363635',background:dark ? '#262626' : '#ffffff'}}; }));
        });
        button('Full screen','fullscreen',function () {
            var promise=document.fullscreenElement === root ? document.exitFullscreen() : root.requestFullscreen();
            if (promise) promise.catch(function () { status.textContent='Fullscreen is unavailable in this browser.'; });
        });
        button('PNG','export',function () {
            network.redraw(); var image=canvas.querySelector('canvas');
            image.toBlob(function (blob) {
                if (!blob) { status.textContent='Export unavailable.'; return; }
                var url=URL.createObjectURL(blob),link=element('a'); link.href=url; link.download='graph.png';
                link.click(); setTimeout(function () { URL.revokeObjectURL(url); },1000);
            },'image/png');
        });
        network.on('click',function (params) {
            root.classList.add('graphify-details');
            if (params.nodes.length) { selected=params.nodes[0]; showNode(selected); }
            else if (params.edges.length) { var edge=byEdge.get(params.edges[0]); detail.replaceChildren(element('strong','',edge.label)); metadata(edge.metadata); }
        });
        network.on('beforeDrawing',function (ctx) {
            ctx.fillStyle=dark ? '#262626' : '#ffffff'; var bounds=network.getViewPosition(),scale=network.getScale();
            ctx.fillRect(bounds.x-canvas.clientWidth/scale,bounds.y-canvas.clientHeight/scale,canvas.clientWidth*2/scale,canvas.clientHeight*2/scale);
            model.hyperedges.forEach(function (hyper) {
                var visible=hyper.nodes.filter(function (id) { return !nodes.get(id).hidden; }); if (visible.length < 2) return;
                var positions=network.getPositions(visible),points=hull(visible.map(function (id) { return positions[id]; }));
                if (points.length < 2) return;
                ctx.save(); ctx.fillStyle='rgba(44,94,170,0.10)'; ctx.strokeStyle='rgba(44,94,170,0.4)'; ctx.lineWidth=12; ctx.lineJoin='round';
                ctx.beginPath(); ctx.moveTo(points[0].x,points[0].y); points.slice(1).forEach(function (p) { ctx.lineTo(p.x,p.y); }); ctx.closePath(); ctx.fill(); ctx.stroke();
                ctx.fillStyle=dark ? '#8ab4e8' : '#2c5eaa'; ctx.font='13px sans-serif'; ctx.fillText(hyper.label,points[0].x,points[0].y-12); ctx.restore();
            });
        });
        var resize=new ResizeObserver(function () { network.redraw(); }); resize.observe(canvas);
        root.addEventListener('fullscreenchange',function () { setTimeout(fit,100); });
        root.graphify={model:model,network:network,nodes:nodes,edges:edges,focus:focus,trace:trace,fit:fit};
        instances.push(root.graphify); requestAnimationFrame(fit); status.textContent='Drag to pan · scroll to zoom · click to inspect';
        return root.graphify;
    }
    function init() { document.querySelectorAll('.graphify-widget').forEach(mount); }
    window.GraphifyWiki={init:init,mount:mount,instances:instances};
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded',init); else init();
})();
