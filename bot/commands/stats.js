const { SlashCommandBuilder } = require('discord.js');
const { apiGet, apiPost } = require('../utils/api');

module.exports = {
  data: new SlashCommandBuilder()
    .setName('stats')
    .setDescription('Statistiques LTD Sandy Shores')
    .addStringOption(o => o
      .setName('periode')
      .setDescription('Période')
      .addChoices(
        { name:'Aujourd\'hui', value:'today' },
        { name:'Cette semaine', value:'week' },
        { name:'Ce mois', value:'month' },
        { name:'Tout temps', value:'all' },
      )),

  async execute(interaction) {
    await interaction.deferReply();
    const periode = interaction.options.getString('periode') || 'week';

    try {
      const [ordersR, stationsR, serviceR] = await Promise.all([
        apiPost('get_orders', null, true).catch(()=>({orders:[]})),
        apiGet('emp_get_stations').catch(()=>({stations:[]})),
        apiGet('get_service_actif').catch(()=>({vendeurs:[]})),
      ]);

      const orders   = ordersR.orders || [];
      const stations = stationsR.stations || [];
      const enService= (serviceR.vendeurs||[]).length;

      // Filtrer par période
      const now   = new Date();
      const start = { today: new Date(now.toISOString().split('T')[0]), week: new Date(now - 7*86400000), month: new Date(now.getFullYear(), now.getMonth(), 1), all: new Date(0) }[periode];
      const filtered = orders.filter(o => new Date(o.date_commande||o.date||0) >= start);

      const total     = filtered.reduce((a,o) => a + parseFloat(o.total||0), 0);
      const livrees   = filtered.filter(o => o.status==='livree'||o.status==='delivered').length;
      const enCours   = filtered.filter(o => !['livree','delivered','annule'].includes(o.status)).length;
      const annulees  = filtered.filter(o => o.status==='annule').length;
      const partCount = filtered.filter(o => !o.company).length;
      const entCount  = filtered.filter(o => !!o.company).length;

      // Stock moyen
      const totalL = stations.reduce((a,s) => a + (parseFloat(s.volume_actuel||s.volumeActuel)||0), 0);
      const totalM = stations.reduce((a,s) => a + (parseFloat(s.volume_max||s.volumeMax)||0), 0);
      const stockPct = totalM > 0 ? Math.round(totalL/totalM*100) : 0;

      const periodeLabels = { today:'Aujourd\'hui', week:'Cette semaine', month:'Ce mois', all:'Tout temps' };

      await interaction.editReply({
        embeds: [{
          title: `📊 Statistiques — ${periodeLabels[periode]}`,
          color: 0xE8C96A,
          fields: [
            { name:'💰 CA total',          value:`**${total.toFixed(2)} $**`,    inline:true },
            { name:'📦 Commandes total',   value:`**${filtered.length}**`,       inline:true },
            { name:'🚚 Livrées',           value:`${livrees}`,                   inline:true },
            { name:'🔵 En cours',          value:`${enCours}`,                   inline:true },
            { name:'❌ Annulées',          value:`${annulees}`,                  inline:true },
            { name:'👤 Particuliers',      value:`${partCount}`,                 inline:true },
            { name:'🏢 Entreprises',       value:`${entCount}`,                  inline:true },
            { name:'👥 Vendeurs en service',value:`${enService}`,               inline:true },
            { name:'⛽ Stock global',      value:`${stockPct}% (${Math.round(totalL).toLocaleString('fr-FR')} L)`, inline:true },
          ],
          footer: { text:'LTD Sandy Shores • Secrétaire' },
          timestamp: new Date().toISOString(),
        }],
      });
    } catch(err) {
      await interaction.editReply(`❌ Erreur : ${err.message}`);
    }
  },
};
