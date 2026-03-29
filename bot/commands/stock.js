const { SlashCommandBuilder } = require('discord.js');
const { getStations, apiGet, apiPost } = require('../utils/api');
const { getChannel, getConfig } = require('../utils/config');

module.exports = {
  data: new SlashCommandBuilder()
    .setName('stock')
    .setDescription('Met à jour le message de suivi des stations'),

  async execute(interaction) {
    await interaction.deferReply({ ephemeral: true });
    const channelId = await getChannel('CHANNEL_STATIONS_PUBLIC') || interaction.channelId;
    await sendOrUpdateStockMessage(interaction.client, channelId);
    await interaction.editReply(`✅ Message mis à jour dans <#${channelId}>`);
  },
};

function buildBar(pct) {
  // Barre avec blocs pleins/vides colorés selon le niveau
  const total  = 15;
  const filled = Math.round((pct / 100) * total);
  const empty  = total - filled;
  const block  = pct >= 60 ? '🟢' : pct >= 30 ? '🟡' : '🔴';
  const eBlock = '⚫';
  return block.repeat(filled) + eBlock.repeat(empty) + ` **${pct}%**`;
}

async function buildStockEmbeds(stations) {
  // Récupérer la config d'affichage depuis la BDD
  const config = await getConfig().catch(()=>({}));
  const display = config.stockDisplay || {
    bar:true, litres:true, pct:true, prix:true,
    ravit:true, photo:true, manquant:false, adresse:false
  };

  return stations.map(s => {
    const actuel = Math.round(parseFloat(s.volume_actuel || s.volumeActuel) || 0);
    const max    = Math.round(parseFloat(s.volume_max    || s.volumeMax)    || 5000);
    const prix   = parseFloat(s.prix_vente || s.prixVente || s.prix_litre  || 0);
    const pct    = max > 0 ? Math.round((actuel / max) * 100) : 0;
    const color  = pct >= 60 ? 0x4ade80 : pct >= 30 ? 0xfbbf24 : 0xf87171;
    const emoji  = pct >= 60 ? '🟢' : pct >= 30 ? '🟡' : '🔴';

    let dernierRav = '—';
    if (s.dernier_rav) {
      const d = new Date(s.dernier_rav.replace(' ','T'));
      dernierRav = d.toLocaleDateString('fr-FR',{day:'2-digit',month:'2-digit',year:'numeric'})
        + ' à ' + d.toLocaleTimeString('fr-FR',{hour:'2-digit',minute:'2-digit'});
    }

    const fields = [];

    // Barre + litres + pct selon config
    let stockValue = '';
    if (display.bar)    stockValue += `${buildBar(pct)} `;
    if (display.pct)    stockValue += `**${pct}%**`;
    if (display.litres) stockValue += `\n${actuel.toLocaleString('fr-FR')} L / ${max.toLocaleString('fr-FR')} L`;
    if (stockValue)     fields.push({ name:'📊 Stock', value:stockValue.trim(), inline:false });

    if (display.prix    && prix > 0)  fields.push({ name:'💰 Prix du litre',  value:`**${prix.toFixed(2)} $/L**`,                          inline:true });
    if (display.ravit)                fields.push({ name:'⏱ Dernier ravit.', value:dernierRav,                                            inline:true });
    if (display.manquant)             fields.push({ name:'🔻 Manquant',      value:`${Math.max(0,max-actuel).toLocaleString('fr-FR')} L`, inline:true });
    if (display.adresse && s.adresse) fields.push({ name:'📍 Adresse',       value:s.adresse,                                            inline:false });

    const embed = {
      title: `${emoji} ${s.nom}`,
      color, fields,
      footer: { text:'LTD Sandy Shores • Mis à jour automatiquement' },
      timestamp: new Date().toISOString(),
    };

    // Photo — URL absolue uniquement
    const photo = s.photo_url || s.photoUrl || '';
    if (display.photo && photo && (photo.startsWith('http://') || photo.startsWith('https://'))) {
      embed.image = { url: photo };
    }

    return embed;
  }).slice(0, 10);
}

async function sendOrUpdateStockMessage(client, channelId, stationsOverride) {
  const stations = stationsOverride || await getStations();
  if (!stations.length) return;

  const embeds = await buildStockEmbeds(stations);

  let stored = null;
  try { const r = await apiGet('bot_get_stock_message'); if (r.success && r.data) stored = r.data; } catch {}

  const channel = await client.channels.fetch(channelId).catch(()=>null);
  if (!channel) { console.error('Canal introuvable:', channelId); return; }

  if (stored && stored.messageId && stored.channelId === channelId) {
    try {
      const msg = await channel.messages.fetch(stored.messageId);
      await msg.edit({ embeds });
      return;
    } catch {}
  }

  const msg = await channel.send({ embeds });
  await apiPost('bot_save_stock_message', { data:{ messageId:msg.id, channelId } }, true).catch(()=>{});
}

module.exports.sendOrUpdateStockMessage = sendOrUpdateStockMessage;
module.exports.buildStockEmbeds = buildStockEmbeds;
