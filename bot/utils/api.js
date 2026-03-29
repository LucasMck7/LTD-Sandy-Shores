const fetch = require('node-fetch');
const API_URL    = process.env.API_URL;
const API_SECRET = process.env.API_SECRET;

async function apiGet(action) {
  const r = await fetch(`${API_URL}?action=${action}`);
  if (!r.ok) throw new Error(`API ${action} HTTP ${r.status}`);
  return r.json();
}
async function apiPost(action, data, auth=false) {
  const headers = { 'Content-Type':'application/json' };
  if (auth) headers['X-API-Secret'] = API_SECRET;
  const r = await fetch(`${API_URL}?action=${action}`, { method:'POST', headers, body:JSON.stringify(data) });
  if (!r.ok) throw new Error(`API ${action} HTTP ${r.status}`);
  return r.json();
}
async function getServiceActif() {
  try { const r=await apiGet('get_service_actif'); return r.success?(r.vendeurs||[]): []; } catch { return []; }
}
async function getStations() {
  try { const r=await apiGet('emp_get_stations'); return r.success?(r.stations||[]): []; } catch { return []; }
}
async function majVolumeStation(stationId, ancien, nouveau, login, nom) {
  return apiPost('emp_majvol', { stationId, ancienVolume:ancien, nouveauVolume:nouveau, login, nom, source:'bot_discord' }, true);
}
async function getBotConfig() {
  try { const r=await apiGet('bot_get_config'); return r.success?(r.config||{}): {}; } catch { return {}; }
}

module.exports = { apiGet, apiPost, getServiceActif, getStations, majVolumeStation, getBotConfig };
