const { getStations, majVolumeStation, apiPost } = require('../utils/api');
const { getRedistribMap, getChannel } = require('../utils/config');

const MONTANT_REGEX   = /[Mm]ontant[^0-9]{0,20}([0-9]+)/;
const REDISTRIB_REGEX = /redistribu?tion\s*[n°nN#]*°?\s*(\d+)/i;

function extractFromText(text) {
  const n = text.match(REDISTRIB_REGEX);
  const m = text.match(MONTANT_REGEX);
  return {
    numero:  n ? parseInt(n[1]) : null,
    montant: m ? parseFloat(m[1]) : null,
  };
}

function extractFromEmbeds(embeds) {
  for (const embed of embeds) {
    const parts = [embed.title||'', embed.description||''];
    for (const f of (embed.fields||[])) parts.push((f.name||'')+' '+(f.value||''));
    const r = extractFromText(parts.join('\n'));
    if (r.numero && r.montant) return r;
  }
  return { numero:null, montant:null };
}

function buildBar(pct) {
  const filled = Math.round((pct/100)*10);
  const segments = ['▏','▎','▍','▌','▋','▊','▉','█'];
  const full  = '█'.repeat(filled);
  const empty = '▁'.repeat(10-filled);
  const arrow = pct>=60?'▲':pct>=30?'▶':'▼';
  const label = pct>=60?'🟢':pct>=30?'🟠':'🔴';
  return label+' **'+pct+'%** '+arrow+' \`'+full+empty+'\`';
}

async function handleFactures(message) {
  let numero = null, montant = null;

  if (message.content && message.content.trim()) {
    const r = extractFromText(message.content);
    numero = r.numero; montant = r.montant;
  }
  if ((!numero||!montant) && message.embeds.length) {
    const r = extractFromEmbeds(message.embeds);
    if (r.numero) numero = r.numero;
    if (r.montant) montant = r.montant;
  }

  if (!numero) return;
  if (!montant || montant <= 0) { await message.react('❓').catch(()=>{}); return; }

  const redistribMap = await getRedistribMap();
  const stationId = redistribMap[String(numero)];

  if (!stationId) {
    console.warn(`⚠️ Redistrib N°${numero} : aucune station configurée`);
    await message.react('⚠️').catch(()=>{});
    const chId = await getChannel('CHANNEL_RECAP_REDISTRIB').catch(()=>null) || '1486864117443723304';
    const ch = await message.client.channels.fetch(chId).catch(()=>null);
    if (ch) await ch.send({ embeds:[{
      title: `⚠️ Redistribution N°${numero} — Non configurée`,
      color: 0xE74C3C,
      description: 'Aucune station associée. Configure dans **Admin → Essence → Redistributions**.',
      fields: [{ name:'💰 Montant', value:montant+' $', inline:true }],
      footer: { text:'LTD Sandy Shores • Secrétaire' },
      timestamp: new Date().toISOString(),
    }]});
    return;
  }

  const stations = await getStations();
  const station = stations.find(s => String(s.id) === String(stationId));
  if (!station) { await message.react('❌').catch(()=>{}); return; }

  const prixLitre = parseFloat(station.prix_vente||station.prixVente||0);
  if (!prixLitre || prixLitre <= 0) { await message.react('❓').catch(()=>{}); return; }

  const litres = Math.round(montant / prixLitre);
  const avant  = parseFloat(station.volume_actuel||station.volumeActuel||0);
  const apres  = Math.max(0, avant - litres);
  const max    = parseFloat(station.volume_max||station.volumeMax||5000);
  const pct    = max > 0 ? Math.round((apres/max)*100) : 0;
  const color  = pct>=60?0x4ade80:pct>=30?0xfbbf24:0xf87171;
  const emoji  = pct>=60?'🟢':pct>=30?'🟡':'🔴';

  console.log(`⛽ N°${numero} → ${station.nom} : ${montant}$ ÷ ${prixLitre}$/L = ${litres}L | ${Math.round(avant)}→${Math.round(apres)}`);

  try {
    await majVolumeStation(stationId, avant, apres, 'bot_discord', 'Secrétaire');

    await apiPost('bot_add_history', {
      redistrib_num: numero,
      station_id:    stationId,
      station_nom:   station.nom,
      montant,
      prix_litre:    prixLitre,
      litres,
      volume_avant:  avant,
      volume_apres:  apres,
      discord_user:  message.author.username||'Bot bancaire',
    }, true).catch(err => console.error('history error:', err.message));

    await message.react('✅').catch(()=>{});

    const chId = await getChannel('CHANNEL_RECAP_REDISTRIB').catch(()=>null) || '1486864117443723304';
    const ch = await message.client.channels.fetch(chId).catch(()=>null);
    if (ch) {
      await ch.send({ embeds:[{
        title:  `⛽ Redistribution N°${numero} — ${station.nom}`,
        color,
        fields: [
          { name:'💰 Montant facturé',  value:`**${montant.toLocaleString('fr-FR')} $**`,       inline:true },
          { name:'💧 Prix du litre',    value:`${prixLitre.toFixed(2)} $/L`,                    inline:true },
          { name:'🔻 Litres consommés', value:`**${litres.toLocaleString('fr-FR')} L**`,        inline:true },
          { name:'📦 Stock avant',      value:`${Math.round(avant).toLocaleString('fr-FR')} L`, inline:true },
          { name:`${emoji} Stock après`,value:`**${Math.round(apres).toLocaleString('fr-FR')} L**`, inline:true },
          { name:'📊 Niveau',           value:buildBar(pct),                    inline:false },
        ],
        footer:    { text:'LTD Sandy Shores • Secrétaire' },
        timestamp: new Date().toISOString(),
      }]});
    }

    console.log(`✅ Redistribution N°${numero} traitée`);
  } catch(err) {
    console.error('handleFactures error:', err.message);
    await message.react('❌').catch(()=>{});
  }
}

module.exports = { handleFactures };
