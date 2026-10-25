import path from 'path';
import { promises as fs } from 'fs';

// Read vagas.json once, at build time, as Next 14 did by default. Since
// Next 15, GET handlers run on every request unless told otherwise, and the
// production image does not ship db/ (only .next/ and public/).
export const dynamic = 'force-static';

export async function GET() {
  try {
    const filePath = path.join(process.cwd(), 'db', 'vagas.json');
    const data = await fs.readFile(filePath, 'utf-8');
    const jsonData = JSON.parse(data);

    return new Response(JSON.stringify(jsonData), {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    });
  } catch (error) {
    console.error('Error reading vagas.json:', error);
    return new Response('Error reading vagas.json', {
      status: 500,
      headers: { 'Content-Type': 'application/json' },
    });
  }
}