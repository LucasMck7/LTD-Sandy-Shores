const { SlashCommandBuilder } = require('discord.js');
const { apiGet } = require('../utils/api');

module.exports = {
  data: new SlashCommandBuilder()
    .setName('annuaire')
    .setDescription('Annuaire des entreprises partenaires LTD Sandy Shores'),

  async execute(interaction) {
    await interaction.deferReply();
    try {
      const r = await apiGet('get_companies');
      const companies = r.companies || r.data?.companies || [];

      if (!companies.length) {
        await interaction.editReply('❌ Aucune entreprise partenaire trouvée.');
        return;
      }

      const fields = companies.map(co => ({
        name: `🏢 ${co.name}`,
        value: `**Secteur :** ${co.sector||'—'}\n**Login :** \`${co.login||'—'}\`${co.contacts&&co.contacts.length?`\n**Contact :** ${co.contacts[0].firstname} ${co.contacts[0].lastname} · ${co.contacts[0].phone}`:''}`,
        inline: true,
      }));

      await interaction.editReply({
        embeds: [{
          title: '📒 Annuaire des partenaires',
          color: 0xE8C96A,
          fields: fields.slice(0, 25),
          footer: { text:`${companies.length} entreprise(s) • LTD Sandy Shores` },
          timestamp: new Date().toISOString(),
        }],
      });
    } catch(err) { await interaction.editReply(`❌ Erreur : ${err.message}`); }
  },
};
