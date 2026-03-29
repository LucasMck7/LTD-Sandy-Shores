const { SlashCommandBuilder } = require('discord.js');
const { getBotConfig, getStations } = require('../utils/api');
module.exports = {
  data: new SlashCommandBuilder()
    .setName('redistrib')
    .setDescription('Voir les correspondances Redistribution N° → Station'),
  async execute(interaction) {
    await interaction.deferReply({ ephemeral: true });
    const config   = await getBotConfig();
    const map      = config.redistribMap || {};
    const stations = await getStations();
    if (!Object.keys(map).length) {
      return interaction.editReply('⚠️ Aucune correspondance configurée.\n👉 Admin du site → onglet **🤖 Bot**');
    }
    const lines = Object.entries(map).map(([num, sid]) => {
      const s = stations.find(x => String(x.id)===String(sid));
      return `**N°${num}** → ${s ? `⛽ ${s.nom}` : `Station ID ${sid}`}`;
    });
    await interaction.editReply({ embeds:[{ title:'📋 Redistributions → Stations', description:lines.join('\n'), color:0xE8C96A }] });
  },
};
