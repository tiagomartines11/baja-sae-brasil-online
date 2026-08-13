import path from 'path';
import { promises as fs } from 'fs';

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