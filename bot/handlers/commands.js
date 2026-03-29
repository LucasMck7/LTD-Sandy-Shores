const { REST, Routes } = require('discord.js');
const fs   = require('fs');
const path = require('path');

async function handleCommands(client) {
  const commands = [];
  const dir = path.join(__dirname, '../commands');
  for (const file of fs.readdirSync(dir).filter(f => f.endsWith('.js'))) {
    const cmd = require(`../commands/${file}`);
    if ('data' in cmd && 'execute' in cmd) {
      client.commands.set(cmd.data.name, cmd);
      commands.push(cmd.data.toJSON());
      console.log(`📌 /${cmd.data.name}`);
    }
  }
  const rest = new REST().setToken(process.env.BOT_TOKEN);
  await rest.put(
    Routes.applicationGuildCommands(process.env.CLIENT_ID, process.env.GUILD_ID),
    { body: commands }
  );
  console.log(`✅ ${commands.length} commande(s) enregistrée(s)`);
}

module.exports = { handleCommands };
