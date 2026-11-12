#!/usr/bin/env node
// Calls Home Assistant's Supervisor API over the WebSocket ("supervisor/api" - the REST
// path /api/hassio/* refuses long-lived tokens) for bin\publish-ha-addon.bat.
//
//   node bin/ha-supervisor.js deploy local_ytan_worker   reload the store, install or rebuild, start
//   node bin/ha-supervisor.js info local_ytan_worker     add-on state
//   node bin/ha-supervisor.js logs local_ytan_worker     the add-on's log (last part)
//   node bin/ha-supervisor.js restart local_ytan_worker  restart it (a tick runs at once)
//
// HA_URL and HA_TOKEN come from the environment or from .env in the repo root.
'use strict';
const fs = require('fs');
const path = require('path');

function env(name) {
    if (process.env[name]) {
        return process.env[name];
    }
    const file = path.join(__dirname, '..', '.env');
    if (fs.existsSync(file)) {
        for (const line of fs.readFileSync(file, 'utf8').split(/\r?\n/)) {
            const m = line.match(/^([A-Z0-9_]+)=(.*)$/);
            if (m && m[1] === name) {
                return m[2].trim();
            }
        }
    }
    throw new Error(name + ' is not set (environment or .env)');
}

function connect() {
    const ws = new WebSocket(env('HA_URL').replace(/^http/, 'ws') + '/api/websocket');
    let id = 1;
    const pending = new Map();
    const ready = new Promise((resolve, reject) => {
        ws.onmessage = (e) => {
            const m = JSON.parse(e.data);
            if (m.type === 'auth_required') {
                ws.send(JSON.stringify({ type: 'auth', access_token: env('HA_TOKEN') }));
            } else if (m.type === 'auth_ok') {
                resolve();
            } else if (m.type === 'auth_invalid') {
                reject(new Error('HA_TOKEN rejected'));
            } else if (m.type === 'result' && pending.has(m.id)) {
                const { ok, fail } = pending.get(m.id);
                pending.delete(m.id);
                m.success ? ok(m.result) : fail(new Error(JSON.stringify(m.error)));
            }
        };
        ws.onerror = () => reject(new Error('cannot connect to ' + env('HA_URL')));
    });
    const call = (endpoint, method = 'get', timeout = 30) => new Promise((ok, fail) => {
        const n = id++;
        pending.set(n, { ok, fail });
        ws.send(JSON.stringify({ id: n, type: 'supervisor/api', endpoint, method, timeout }));
    });
    return { ready, call, close: () => ws.close() };
}

async function main() {
    const [command, slug] = process.argv.slice(2);
    if (!command || !slug) {
        throw new Error('usage: node bin/ha-supervisor.js deploy|info|logs <addon slug>');
    }
    const ha = connect();
    await ha.ready;
    try {
        if (command === 'info') {
            const info = await ha.call(`/addons/${slug}/info`);
            console.log(JSON.stringify({ state: info.state, version: info.version, installed: info.version !== null }, null, 2));
        } else if (command === 'logs') {
            // plain text: not over the WebSocket, the REST proxy allows add-on logs for an admin token
            const res = await fetch(env('HA_URL') + `/api/hassio/addons/${slug}/logs`, { headers: { Authorization: 'Bearer ' + env('HA_TOKEN') } });
            console.log(await res.text());
        } else if (command === 'restart') {
            await ha.call(`/addons/${slug}/restart`, 'post', 120);
            console.log(slug + ' restarted');
        } else if (command === 'deploy') {
            await ha.call('/store/reload', 'post', 120);
            let installed = false;
            try {
                installed = (await ha.call(`/addons/${slug}/info`)).version !== null;
            } catch (e) {
                installed = false;
            }
            // building the image on the Pi takes several minutes (Python packages)
            if (installed) {
                console.log('rebuilding ' + slug + ' ...');
                await ha.call(`/addons/${slug}/rebuild`, 'post', 1800);
            } else {
                console.log('installing ' + slug + ' ...');
                await ha.call(`/store/addons/${slug}/install`, 'post', 1800);
            }
            await ha.call(`/addons/${slug}/start`, 'post', 120);
            console.log(slug + ' started');
        } else {
            throw new Error('unknown command ' + command);
        }
    } finally {
        ha.close();
    }
}

main().catch((e) => {
    console.error(e.message);
    process.exit(1);
});
