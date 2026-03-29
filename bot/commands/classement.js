const { SlashCommandBuilder } = require('discord.js');
const { apiPost } = require('../utils/api');

module.exports = {
  data: new SlashCommandBuilder()
    .setName('classement')
    .setDescription('Classement des vendeurs par CA')
    .addStringOption(o => o.setName('periode')
      .setDescription('Période')
      .addChoices(
        { name:'Cette semaine', value:'week' },
        { name:'Ce mois', value:'month' },
        { name:'Tout temps', value:'all' },
      )),

  async execute(interaction) {
    await interaction.deferReply();
    const periode = interaction.options.getString('periode') || 'week';
    try {
      const r = await apiPost('bot_get_classement', { periode }, true);
      const classement = (r.classement||[]).slice(0, 10);

      if (!classement.length) {
        await interaction.editReply('❌ Aucune donnée de classement disponible.');
        return;
      }

      const medals = ['🥇','🥈','🥉'];
      const periodeL = { week:'Cette semaine', month:'Ce mois', all:'Tout temps' };
      const lines = classement.map((e,i) =>
        `${medals[i]||`**${i+1}.**`} **${e.prenom} ${e.nom}** — ${parseFloat(e.ca_total||0).toFixed(2)} $ (${e.sessions||0} sessions)`
      ).join('\n');

      await interaction.editReply({
        embeds: [{
          title: `🏆 Classement vendeurs — ${periodeL[periode]}`,
          color: 0xF0C040,
          description: lines,
          footer: { text:'LTD Sandy Shores • Secrétaire' },
          timestamp: new Date().toISOString(),
        }],
      });
    } catch(err) { await interaction.editReply(`❌ Erreur : ${err.message}`); }
  },
};
