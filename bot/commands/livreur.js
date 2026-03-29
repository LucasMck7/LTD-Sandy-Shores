const { SlashCommandBuilder } = require('discord.js');
const { apiPost } = require('../utils/api');

module.exports = {
  data: new SlashCommandBuilder()
    .setName('livreur')
    .setDescription('Voir les livraisons assignées à un livreur')
    .addStringOption(o => o.setName('nom').setDescription('Nom du livreur').setRequired(true)),

  async execute(interaction) {
    await interaction.deferReply({ ephemeral: true });
    const nom = interaction.options.getString('nom').toLowerCase();
    try {
      const r = await apiPost('get_orders', null, true);
      const orders = (r.orders||[]).filter(o =>
        o.livreurName && o.livreurName.toLowerCase().includes(nom)
      );

      if (!orders.length) {
        await interaction.editReply(`❌ Aucune livraison trouvée pour **${nom}**.`);
        return;
      }

      const enCours = orders.filter(o => !['livree','delivered','annule'].includes(o.status));
      const total   = orders.reduce((a,o) => a + parseFloat(o.total||0), 0);
      const statusL = { new:'🟡', pending:'🟡', progress:'🔵', done:'📦', livree:'🚚', annule:'❌' };

      const lines = enCours.slice(0, 10).map(o =>
        `${statusL[o.status]||'•'} **${o.ref||o.id}** — ${o.company||`${o.prenom} ${o.nom}`} — ${parseFloat(o.total||0).toFixed(2)} $`
      ).join('\n') || 'Aucune livraison en cours';

      await interaction.editReply({
        embeds: [{
          title: `🚗 Livraisons — ${orders[0].livreurName}`,
          color: 0x4A90D9,
          fields: [
            { name:'📋 En cours', value:lines, inline:false },
            { name:'📦 Total commandes', value:String(orders.length), inline:true },
            { name:'💰 CA total',        value:`${total.toFixed(2)} $`, inline:true },
          ],
          footer:{ text:'LTD Sandy Shores • Secrétaire' },
          timestamp: new Date().toISOString(),
        }],
      });
    } catch(err) { await interaction.editReply(`❌ Erreur : ${err.message}`); }
  },
};
