const { SlashCommandBuilder } = require('discord.js');
const { apiPost, apiGet } = require('../utils/api');

module.exports = {
  data: new SlashCommandBuilder()
    .setName('commande')
    .setDescription('Rechercher une commande par référence')
    .addStringOption(o => o.setName('ref').setDescription('Référence de la commande (ex: CMD-001)').setRequired(true)),

  async execute(interaction) {
    await interaction.deferReply({ ephemeral: true });
    const ref = interaction.options.getString('ref').toUpperCase();

    try {
      // Chercher dans commandes particuliers
      const rPart = await apiPost('get_order_by_ref', { ref }, true).catch(()=>null);
      // Chercher dans commandes entreprises
      const rEnt  = await apiGet('ent_get_data').catch(()=>null);

      let order = null;
      let type  = null;

      if (rPart && rPart.success && rPart.order) {
        order = rPart.order; type = 'particulier';
      } else if (rEnt && rEnt.data && rEnt.data.orders) {
        const found = rEnt.data.orders.find(o => o.id === ref || o.ref === ref);
        if (found) { order = found; type = 'entreprise'; }
      }

      if (!order) {
        await interaction.editReply(`❌ Commande **${ref}** introuvable.`);
        return;
      }

      const statusLabels = { new:'🟡 Nouvelle', pending:'🟡 En attente', progress:'🔵 En préparation', done:'📦 Prête', livree:'🚚 Livrée', annule:'❌ Annulée' };
      const statusColors = { new:0xF0C040, pending:0xF0C040, progress:0x4A90D9, done:0x5DB85D, livree:0x27AE60, annule:0xE74C3C };
      const status = order.status || 'new';

      await interaction.editReply({
        embeds: [{
          title: `🛒 Commande ${ref}`,
          color: statusColors[status] || 0x888888,
          fields: [
            { name:'📋 Statut',     value: statusLabels[status]||status,                                  inline:true },
            { name:'🏷 Type',       value: type==='entreprise' ? `🏢 ${order.company}` : '👤 Particulier', inline:true },
            { name:'👤 Contact',    value: order.contact || `${order.prenom||''} ${order.nom||''}`,       inline:true },
            { name:'📅 Livraison',  value: `${order.deliveryDate||order.date||'—'} ${order.slot||order.heure||''}`, inline:true },
            { name:'💰 Total',      value: `**${parseFloat(order.total||0).toFixed(2)} $**`,              inline:true },
            { name:'🚗 Livreur',    value: order.livreurName || '—',                                     inline:true },
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
