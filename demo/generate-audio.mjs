import { mkdir, writeFile, copyFile, cp } from "node:fs/promises";
import { fileURLToPath } from "node:url";

const destination = fileURLToPath(new URL("../dist-demo/", import.meta.url));
const durations = [24, 28, 24, 32, 28, 24];
const roots = [174.614, 196, 220, 130.813, 146.832, 261.626];
const rate = 22050;
await mkdir(`${destination}/audio`, { recursive: true });
for (let index = 0; index < durations.length; index++) {
  const seconds = durations[index];
  const count = rate * seconds;
  const wav = Buffer.alloc(44 + count * 2);
  wav.write("RIFF", 0);
  wav.writeUInt32LE(wav.length - 8, 4);
  wav.write("WAVEfmt ", 8);
  wav.writeUInt32LE(16, 16);
  wav.writeUInt16LE(1, 20);
  wav.writeUInt16LE(1, 22);
  wav.writeUInt32LE(rate, 24);
  wav.writeUInt32LE(rate * 2, 28);
  wav.writeUInt16LE(2, 32);
  wav.writeUInt16LE(16, 34);
  wav.write("data", 36);
  wav.writeUInt32LE(count * 2, 40);
  const notes = [0, 7, 12, 4, 7, 16, 12, 7];
  for (let sample = 0; sample < count; sample++) {
    const time = sample / rate;
    const beat = time % 0.6;
    const note = notes[Math.floor(time / 0.6) % notes.length];
    const frequency = roots[index] * 2 ** (note / 12);
    const envelope = Math.min(1, beat / 0.018) * Math.exp(-beat * 7);
    const pluck = Math.sin(2 * Math.PI * frequency * time) * envelope;
    const pad =
      (Math.sin(2 * Math.PI * roots[index] * time) +
        Math.sin(2 * Math.PI * roots[index] * 1.5 * time)) *
      0.2;
    const fade = Math.min(1, time / 1.5, (seconds - time) / 2);
    wav.writeInt16LE(
      Math.round((pluck * 0.13 + pad * 0.1) * fade * 32767),
      44 + sample * 2,
    );
  }
  await writeFile(`${destination}/audio/sample-${index + 1}.wav`, wav);
}
await copyFile(
  fileURLToPath(new URL("../public/favicon.svg", import.meta.url)),
  `${destination}/favicon.svg`,
);
await cp(
  fileURLToPath(new URL("../public/licenses", import.meta.url)),
  `${destination}/licenses`,
  { recursive: true },
);
await writeFile(`${destination}/.nojekyll`, "");
await writeFile(`${destination}/robots.txt`, "User-agent: *\nDisallow: /\n");
console.log(
  `Generated ${durations.length} original synthetic audio samples and static demo assets.`,
);
