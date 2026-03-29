const { apiGet, apiPost } = require('../utils/api');
const { getChannel, getEntConfig, getGlobalRole } = require('../utils/config');

const MSG_IDS = {};

// Charger les MSG_IDS depuis la BDD au démarrage
async function loadMsgIds() {
  try {
    const r = await apiGet('bot_get_msg_ids');
    if (r.success && r.data) Object.assign(MSG_IDS, r.data);
    console.log(`📨 ${Object.keys(MSG_IDS).length} message(s) Discord chargés`);
  } catch {}
}

async function saveMsgId(orderId, data) {
  MSG_IDS[orderId] = data;
  try {
    await apiPost('bot_save_msg_ids', { data: MSG_IDS }, true);
  } catch {}
}

function fmtP(n){ return Number(n||0).toFixed(2)+' $'; }

function statusColor(s){
  return {new:0xF0C040,pending:0xF0C040,progress:0x4A90D9,done:0x5DB85D,livree:0x27AE60,annule:0xE74C3C}[s]||0x888888;
}
function statusLabel(s){
  return {new:'🟡 Nouvelle',pending:'🟡 En attente',progress:'🔵 En préparation',done:'📦 Prête',livree:'🚚 Livrée',annule:'❌ Annulée'}[s]||s;
}

function buildOrderButtons(orderId, type='entreprise'){
  return [
    { type:1, components:[
      { type:2, style:2, label:'En préparation', custom_id:`status_progress_${type}_${orderId}`, emoji:{name:'🔵'} },
      { type:2, style:2, label:'Prête',           custom_id:`status_done_${type}_${orderId}`,     emoji:{name:'📦'} },
    ]},
    { type:1, components:[
      { type:2, style:3, label:'Livrée',  custom_id:`status_livree_${type}_${orderId}`, emoji:{name:'🚚'} },
      { type:2, style:4, label:'Annuler', custom_id:`status_annule_${type}_${orderId}`, emoji:{name:'❌'} },
    ]},
  ];
}

// Embed récap commande particulier (complet)
function buildParticulierEmbed(order){
  const items = (order.items||[]).map(i=>
    `> ${i.emoji||'📦'} **${i.name}** × ${i.qty} = ${fmtP((i.price||0)*i.qty)}`
  ).join('\n')||'—';
  return {
    title:`🛒 Nouvelle commande — ${order.ref||order.id}`,
    color:0xE8C96A,
    fields:[
      {name:'👤 Client',    value:`${order.prenom||''} ${order.nom||''} · ${order.tel||'—'}`, inline:true},
      {name:'📍 Adresse',   value:order.adresse||'—',                                          inline:true},
      {name:'📅 Livraison', value:`${order.date||''} ${order.heure||order.slot||''}`,          inline:true},
      {name:'📦 Produits',  value:items,                                                        inline:false},
      {name:'💰 Total',     value:`**${fmtP(order.total)}**`,                                  inline:true},
      {name:'💳 Paiement',  value:order.paiement||order.payMethod||'—',                        inline:true},
    ],
    footer:{text:'LTD Sandy Shores • Espace Particulier'},
    timestamp:new Date().toISOString(),
  };
}

// Embed récap commande entreprise (complet — pour salon admin)
function buildEntrepriseEmbed(order, products){
  const items = (order.items||[]).map(i=>{
    const p = (products||[]).find(x=>String(x.id)===String(i.pid));
    return `> ${p?p.emoji||'📦':'📦'} **${p?p.name:'Produit #'+i.pid}** × ${i.qty}`;
  }).join('\n')||'—';
  return {
    title:`🛒 Nouvelle commande — ${order.id}`,
    color:0xE8C96A,
    fields:[
      {name:'🏢 Entreprise', value:order.company||'—',                                              inline:true},
      {name:'👤 Contact',    value:`${order.contact||'—'} · ${order.phone||'—'}`,                  inline:true},
      {name:'📅 Livraison',  value:`${order.deliveryDate||''} ${order.slot||''}`,                  inline:true},
      {name:'📦 Produits',   value:items,                                                           inline:false},
      {name:'💰 Total',      value:`**${fmtP(order.total)}**`,                                     inline:true},
      {name:'💳 Paiement',   value:order.payMethod==='iban'?`🏦 Virement${order.iban?' — '+order.iban:''}`:'💵 Livraison', inline:true},
    ],
    footer:{text:'LTD Sandy Shores • Espace Entreprise'},
    timestamp:new Date().toISOString(),
  };
}

// Embed récap SIMPLIFIÉ pour l'entreprise (salon privé)
function buildEntrepriseRecapSimple(order, products){
  const items = (order.items||[]).map(i=>{
    const p = (products||[]).find(x=>String(x.id)===String(i.pid));
    return `${p?p.emoji||'📦':'📦'} **${p?p.name:'Produit #'+i.pid}** × ${i.qty}`;
  }).join('\n')||'—';
  return {
    title:`📋 Confirmation de commande — ${order.id}`,
    color:0x4A90D9,
    description:`Votre commande a bien été reçue et est en cours de traitement.`,
    fields:[
      {name:'📦 Produits commandés', value:items,                  inline:false},
      {name:'💰 Total',              value:`**${fmtP(order.total)}**`, inline:true},
      {name:'📅 Livraison souhaitée',value:`${order.deliveryDate||''} ${order.slot||''}`, inline:true},
      {name:'💳 Paiement',           value:order.payMethod==='iban'?'Virement IBAN':'À la livraison', inline:true},
    ],
    footer:{text:'LTD Sandy Shores • Service entreprises'},
    timestamp:new Date().toISOString(),
  };
}

async function handleNotify(client, data) {
  const { type, order, rolePing, channelId, products } = data;
  if (!type || !order) return { success:false, error:'Paramètres manquants' };

  try {
    switch(type) {

      case 'new_order_particulier': {
        const chId = channelId || await getChannel('CHANNEL_COMMANDES_PART');
        if (!chId) return { success:false, error:'CHANNEL_COMMANDES_PART non configuré' };
        const channel = await client.channels.fetch(chId);
        const roleId  = await getGlobalRole('ROLE_LIVREUR');
        const mention = roleId ? `<@&${roleId}> ` : '';
        const msg = await channel.send({
          content: `${mention}📬 Nouvelle commande **particulier**`,
          embeds:  [buildParticulierEmbed(order)],
          components: buildOrderButtons(order.ref||order.id, 'particulier'),
        });
        await saveMsgId(order.ref||order.id, { id:msg.id, channelId:chId, type:'particulier' });
        return { success:true };
      }

      case 'new_order_entreprise': {
        // 1. Notifier le canal admin (embed complet + boutons)
        const adminChId = channelId || await getChannel('CHANNEL_COMMANDES_ENT');
        if (adminChId) {
          const adminCh = await client.channels.fetch(adminChId);
          const roleId  = await getGlobalRole('ROLE_LIVREUR');
          const mention = roleId ? `<@&${roleId}> ` : '';
          const adminMsg = await adminCh.send({
            content: `${mention}🏢 Nouvelle commande **${order.company}**`,
            embeds:  [buildEntrepriseEmbed(order, products)],
            components: buildOrderButtons(order.id, 'entreprise'),
          });
          await saveMsgId(order.id, { id:adminMsg.id, channelId:adminChId, type:'entreprise' });
        }

        // 2. Notifier l'entreprise dans son salon privé — récap simplifié UNIQUEMENT
        // Chercher le channelEntreprise dans la config BDD
        const { getConfig } = require('../utils/config');
        const config = await getConfig();
        const entChannels = config.entChannels || {};

        // Trouver l'entreprise par nom
        let entChId = null;
        let entRolePing = rolePing || null;
        // Chercher dans entChannels par companyId
        for (const [id, cfg] of Object.entries(entChannels)) {
          if (cfg.channelId) {
            // On cherche par nom d'entreprise dans la config
            entChId = cfg.channelId;
            if (!entRolePing && cfg.rolePing) entRolePing = cfg.rolePing;
            break; // simplifié — améliorer si multi-entreprise
          }
        }

        // Fallback : chercher channelEntreprise dans les données entreprises
        if (!entChId) {
          const entData = await apiGet('get_companies').catch(()=>null);
          if (entData && entData.companies) {
            const co = entData.companies.find(c => c.name === order.company);
            if (co) {
              entChId = co.channelEntreprise || null;
              if (!entRolePing) entRolePing = co.rolePing || null;
            }
          }
        }

        if (entChId) {
          const entCh = await client.channels.fetch(entChId).catch(()=>null);
          if (entCh) {
            const roleStr = entRolePing ? `<@&${entRolePing.replace(/[^0-9]/g,'')}>` : '';
            await entCh.send({
              content: roleStr ? `${roleStr} Nouvelle commande reçue !` : '📋 Nouvelle commande reçue !',
              embeds: [buildEntrepriseRecapSimple(order, products)],
            });
          }
        }

        return { success:true };
      }

      case 'status_change': {
        const stored = MSG_IDS[order.id||order.ref];
        if (!stored) return { success:true };
        const embed = {
          title:`${statusLabel(order.status)} — ${order.id||order.ref}`,
          color:statusColor(order.status),
          fields:[
            {name:order.company?'🏢 Entreprise':'👤 Client', value:order.company||`${order.prenom||''} ${order.nom||''}`, inline:true},
            {name:'💰 Total', value:fmtP(order.total), inline:true},
          ],
          footer:{text:'LTD Sandy Shores • Mise à jour statut'},
          timestamp:new Date().toISOString(),
        };
        if (order.livreurName) embed.fields.push({name:'🚗 Livreur',value:order.livreurName,inline:true});
        const ch  = await client.channels.fetch(stored.channelId);
        const msg = await ch.messages.fetch(stored.id).catch(()=>null);
        const done = ['livree','annule'].includes(order.status);
        if (msg) {
          await msg.edit({ embeds:[embed], components:done?[]:buildOrderButtons(order.id||order.ref,stored.type) });
        }
        return { success:true };
      }

      default: return { success:false, error:`Type inconnu: ${type}` };
    }
  } catch(err) {
    console.error('handleNotify:', err.message);
    return { success:false, error:err.message };
  }
}

// Boutons de statut Discord
async function handleButtonInteraction(interaction) {
  const id = interaction.customId;
  if (!id.startsWith('status_')) return false;

  const parts   = id.split('_');
  const status  = parts[1];
  const type    = parts[2];
  const orderId = parts.slice(3).join('_');

  await interaction.deferUpdate();

  try {
    const action = type==='particulier' ? 'update_order_status_part' : 'ent_changer_statut';
    await apiPost(action, { id:orderId, ref:orderId, status }, true);

    const labels = { progress:'🔵 En préparation', done:'📦 Prête', livree:'🚚 Livrée', annule:'❌ Annulée' };
    const colors = { progress:0x4A90D9, done:0x5DB85D, livree:0x27AE60, annule:0xE74C3C };

    const oldEmbed = interaction.message.embeds[0]?.toJSON() || {};
    const newEmbed = {
      ...oldEmbed,
      color: colors[status]||0x888888,
      footer: { text:`LTD Sandy Shores • Modifié par ${interaction.user.username}` },
      timestamp: new Date().toISOString(),
    };

    const done = ['livree','annule'].includes(status);
    await interaction.message.edit({
      embeds: [newEmbed],
      components: done ? [] : buildOrderButtons(orderId, type),
    });

    await interaction.followUp({ content:`✅ Statut mis à jour : **${labels[status]||status}** par ${interaction.user}`, ephemeral:true });
  } catch(err) {
    console.error('handleButtonInteraction:', err.message);
    await interaction.followUp({ content:`❌ Erreur : ${err.message}`, ephemeral:true });
  }
  return true;
}

module.exports = { handleNotify, handleButtonInteraction };
