// ══ Config centralisée depuis la BDD du site ═══════════
// Le bot lit sa config depuis bot_get_config au lieu des variables .env
const { apiGet } = require('./api');

let _cache = null;
let _cacheTime = 0;
const TTL = 30000; // 30 secondes

async function getConfig() {
  const now = Date.now();
  if (_cache && (now - _cacheTime) < TTL) return _cache;
  try {
    const r = await apiGet('bot_get_config');
    if (r.success && r.config) {
      _cache = r.config;
      _cacheTime = now;
      return _cache;
    }
  } catch (err) {
    console.error('getConfig error:', err.message);
  }
  return _cache || {};
}

async function getChannel(key) {
  const config = await getConfig();
  return (config.channels||{})[key] || process.env[key] || null;
}

async function getGlobalRole(key) {
  const config = await getConfig();
  return (config.globalRoles||{})[key] || process.env[key] || null;
}

async function getGradeRole(key) {
  const config = await getConfig();
  return (config.roles||{})[key] || process.env[key] || null;
}

async function getEntConfig(companyId) {
  const config = await getConfig();
  return ((config.entChannels||{})[String(companyId)]) || {};
}

async function getRedistribMap() {
  const config = await getConfig();
  return config.redistribMap || {};
}

function clearCache() { _cache = null; _cacheTime = 0; }

module.exports = { getConfig, getChannel, getGlobalRole, getGradeRole, getEntConfig, getRedistribMap, clearCache };
