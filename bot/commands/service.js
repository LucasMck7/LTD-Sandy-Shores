const { SlashCommandBuilder, PermissionFlagsBits } = require('discord.js');
const { buildServiceEmbed } = require('../handlers/stats');
const { sendServiceMessage } = require('../handlers/service');

module.exports = {
  data: new SlashCommandBuilder()
    .setName('service')
    .setDescription('Gestion du service LTD Sandy Shores')
    .addSubcommand(sub => sub
      .setName('voir')
      .setDescription('Voir les vendeurs actuellement en service'))
    .addSubcommand(sub => sub
      .setName('panneau')
      .setDescription('Envoyer le panneau de prise/fin de service dans ce salon (admin)')
      .setDefaultMemberPermissions(PermissionFlagsBits.Administrator)),

  async execute(interaction) {
    const sub = interaction.options.getSubcommand();

    if (sub === 'voir') {
      await interaction.deferReply();
      const reply = await buildServiceEmbed();
      await interaction.editReply(reply);
    }

    else if (sub === 'panneau') {
      await interaction.deferReply({ ephemeral: true });
      await sendServiceMessage(interaction.channel, interaction.client);
      await interaction.editReply('✅ Panneau de service envoyé dans ce salon.');
    }
  },
};
